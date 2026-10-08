// Package yourimageshare is the official Go client for the YourImageShare
// upload API (https://yourimageshare.com/about/api). It mirrors the
// existing JS (npm), Python (PyPI), and PHP (Packagist) SDKs - same
// method names, same result shapes, same error type - just idiomatic Go
// on top (methods return (result, error), not exceptions).
//
// Zero third-party dependencies - only the standard library.
package yourimageshare

import (
	"bytes"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"mime/multipart"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"time"
)

// DefaultBaseURL is used when no WithBaseURL option is given.
const DefaultBaseURL = "https://yourimageshare.com/api"

const sdkVersion = "1.1.0"

const (
	// chunkThreshold: files above this size are sent in pieces (one request
	// can carry at most 100 MB).
	chunkThreshold = 90 << 20
	// chunkSize: one piece of a chunked upload (the API accepts at most 5 MB).
	chunkSize = 5 << 20
)

// Client talks to the YourImageShare upload API. Create one with
// NewClient; a Client is safe for concurrent use by multiple goroutines
// (it holds no mutable state after construction).
type Client struct {
	apiKey     string
	baseURL    string
	httpClient *http.Client
}

// Option configures a Client. See WithBaseURL and WithHTTPClient.
type Option func(*Client)

// WithBaseURL overrides the API base URL - mainly for testing against a
// different environment. Defaults to DefaultBaseURL.
func WithBaseURL(baseURL string) Option {
	return func(c *Client) {
		c.baseURL = baseURL
	}
}

// WithHTTPClient overrides the *http.Client used for requests, e.g. to set
// a custom timeout or transport. Defaults to a client with a 30s timeout.
func WithHTTPClient(httpClient *http.Client) Option {
	return func(c *Client) {
		c.httpClient = httpClient
	}
}

// NewClient creates a Client. apiKey is required - get one from the API
// tab at https://yourimageshare.com/my-account.
func NewClient(apiKey string, opts ...Option) (*Client, error) {
	if apiKey == "" {
		return nil, fmt.Errorf("yourimageshare: apiKey is required")
	}

	c := &Client{
		apiKey:     apiKey,
		baseURL:    DefaultBaseURL,
		httpClient: &http.Client{Timeout: 30 * time.Second},
	}
	for _, opt := range opts {
		opt(c)
	}
	return c, nil
}

// UploadOptions are the optional parameters for Upload/UploadReader.
type UploadOptions struct {
	// ExpiresIn auto-deletes the upload after this many seconds (60 to
	// 2,592,000 = 30 days). Zero means a permanent upload.
	ExpiresIn int
	// AllowDuplicate stores a new copy even if your account already
	// uploaded this exact file (otherwise that upload is returned with
	// Duplicate set).
	AllowDuplicate bool
	// OnProgress, if set, is called after each piece of a chunked upload
	// (files over 90 MB) with the bytes sent so far and the total.
	OnProgress func(sent, total int64)
}

// Upload uploads a local file by path (up to 200 MB). Files over 90 MB are
// sent in 5 MB pieces automatically.
func (c *Client) Upload(filePath string, opts *UploadOptions) (*UploadResult, error) {
	f, err := os.Open(filePath)
	if err != nil {
		return nil, fmt.Errorf("yourimageshare: %w", err)
	}
	defer f.Close()

	if info, err := f.Stat(); err == nil && info.Size() > chunkThreshold {
		return c.uploadChunked(f, info.Size(), filepath.Base(filePath), opts)
	}
	return c.UploadReader(f, filepath.Base(filePath), opts)
}

// UploadURL uploads from a public http(s) link: the server downloads the
// file itself (up to 200 MB).
func (c *Client) UploadURL(fileURL string, opts *UploadOptions) (*UploadResult, error) {
	return c.postUpload(map[string]string{"url": fileURL}, opts)
}

func (c *Client) uploadChunked(r io.Reader, size int64, filename string, opts *UploadOptions) (*UploadResult, error) {
	id := make([]byte, 16)
	if _, err := rand.Read(id); err != nil {
		return nil, fmt.Errorf("yourimageshare: %w", err)
	}
	uploadID := hex.EncodeToString(id)
	total := (size + chunkSize - 1) / chunkSize
	buf := make([]byte, chunkSize)
	var sent int64

	for index := int64(0); index < total; index++ {
		n, err := io.ReadFull(r, buf)
		if err != nil && err != io.ErrUnexpectedEOF && err != io.EOF {
			return nil, fmt.Errorf("yourimageshare: reading file: %w", err)
		}
		fields := map[string]string{"upload_id": uploadID, "index": strconv.FormatInt(index, 10), "total": strconv.FormatInt(total, 10)}
		for attempt := 1; ; attempt++ {
			body, contentType, err := multipartBody(fields, "chunk", buf[:n])
			if err != nil {
				return nil, fmt.Errorf("yourimageshare: %w", err)
			}
			req, err := http.NewRequest(http.MethodPost, strings.TrimRight(c.baseURL, "/")+"/chunk", body)
			if err != nil {
				return nil, fmt.Errorf("yourimageshare: %w", err)
			}
			req.Header.Set("Content-Type", contentType)
			c.setCommonHeaders(req)
			err = c.do(req, nil)
			if err == nil {
				break
			}
			// network errors and 5xx are retried; anything the server refused (4xx) is final
			var apiErr *APIError
			if attempt >= 3 || (errors.As(err, &apiErr) && apiErr.Status >= 400 && apiErr.Status < 500) {
				return nil, err
			}
		}
		sent += int64(n)
		if opts != nil && opts.OnProgress != nil {
			opts.OnProgress(sent, size)
		}
	}
	return c.postUpload(map[string]string{"upload_id": uploadID, "filename": filename}, opts)
}

// postUpload sends POST /api with plain form fields (a link or a finished
// chunked upload) plus the upload options.
func (c *Client) postUpload(fields map[string]string, opts *UploadOptions) (*UploadResult, error) {
	if opts != nil && opts.ExpiresIn > 0 {
		fields["expires_in"] = strconv.Itoa(opts.ExpiresIn)
	}
	if opts != nil && opts.AllowDuplicate {
		fields["allow_duplicate"] = "1"
	}
	body, contentType, err := multipartBody(fields, "", nil)
	if err != nil {
		return nil, fmt.Errorf("yourimageshare: %w", err)
	}
	req, err := http.NewRequest(http.MethodPost, c.baseURL, body)
	if err != nil {
		return nil, fmt.Errorf("yourimageshare: %w", err)
	}
	req.Header.Set("Content-Type", contentType)
	c.setCommonHeaders(req)

	var out struct {
		Data UploadResult `json:"data"`
	}
	if err := c.do(req, &out); err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// multipartBody builds an in-memory multipart/form-data body; fileField
// (if not empty) adds data as a file part.
func multipartBody(fields map[string]string, fileField string, data []byte) (*bytes.Buffer, string, error) {
	var buf bytes.Buffer
	mw := multipart.NewWriter(&buf)
	for name, value := range fields {
		if err := mw.WriteField(name, value); err != nil {
			return nil, "", err
		}
	}
	if fileField != "" {
		part, err := mw.CreateFormFile(fileField, "piece")
		if err != nil {
			return nil, "", err
		}
		if _, err := part.Write(data); err != nil {
			return nil, "", err
		}
	}
	if err := mw.Close(); err != nil {
		return nil, "", err
	}
	return &buf, mw.FormDataContentType(), nil
}

// UploadReader uploads from any io.Reader (an open file, a network stream,
// an in-memory buffer) - useful when the data isn't already a file on
// disk. filename should include a real extension so the server can infer
// the content type correctly.
func (c *Client) UploadReader(r io.Reader, filename string, opts *UploadOptions) (*UploadResult, error) {
	if opts == nil {
		opts = &UploadOptions{}
	}

	// Streams the multipart body via io.Pipe instead of buffering the
	// whole file in memory first - uploads can be up to 200MB (video), and
	// loading that wholesale would be wasteful for a library meant to be
	// used in resource-constrained places too.
	pr, pw := io.Pipe()
	mw := multipart.NewWriter(pw)

	go func() {
		defer pw.Close()
		defer mw.Close()

		part, err := mw.CreateFormFile("uploads", filename)
		if err != nil {
			pw.CloseWithError(err)
			return
		}
		if _, err := io.Copy(part, r); err != nil {
			pw.CloseWithError(err)
			return
		}
		if opts.ExpiresIn > 0 {
			if err := mw.WriteField("expires_in", strconv.Itoa(opts.ExpiresIn)); err != nil {
				pw.CloseWithError(err)
				return
			}
		}
		if opts.AllowDuplicate {
			if err := mw.WriteField("allow_duplicate", "1"); err != nil {
				pw.CloseWithError(err)
				return
			}
		}
	}()

	req, err := http.NewRequest(http.MethodPost, c.baseURL, pr)
	if err != nil {
		return nil, fmt.Errorf("yourimageshare: %w", err)
	}
	req.Header.Set("Content-Type", mw.FormDataContentType())
	c.setCommonHeaders(req)

	var body struct {
		Data UploadResult `json:"data"`
	}
	if err := c.do(req, &body); err != nil {
		return nil, err
	}
	return &body.Data, nil
}

// List returns your uploads, newest first, 50 per page. page < 2 fetches
// the first page.
func (c *Client) List(page int) (*ListResult, error) {
	u := c.baseURL
	if page > 1 {
		u += "?" + url.Values{"page": {strconv.Itoa(page)}}.Encode()
	}

	req, err := http.NewRequest(http.MethodGet, u, nil)
	if err != nil {
		return nil, fmt.Errorf("yourimageshare: %w", err)
	}
	c.setCommonHeaders(req)

	var result ListResult
	if err := c.do(req, &result); err != nil {
		return nil, err
	}
	return &result, nil
}

// Delete removes one of your uploads by id. Returns an *APIError on a
// 404/401.
func (c *Client) Delete(id string) error {
	req, err := http.NewRequest(http.MethodDelete, c.baseURL+"/"+url.PathEscape(id), nil)
	if err != nil {
		return fmt.Errorf("yourimageshare: %w", err)
	}
	c.setCommonHeaders(req)

	return c.do(req, nil)
}

func (c *Client) setCommonHeaders(req *http.Request) {
	req.Header.Set("X-API-Key", c.apiKey)
	req.Header.Set("User-Agent", "yourimageshare-go/"+sdkVersion)
}

// do executes req, decodes a successful JSON body into out (if non-nil),
// and returns *APIError for any non-2xx response or a `{"type":"error"}`
// payload.
func (c *Client) do(req *http.Request, out interface{}) error {
	resp, err := c.httpClient.Do(req)
	if err != nil {
		return fmt.Errorf("yourimageshare: request failed: %w", err)
	}
	defer resp.Body.Close()

	raw, err := io.ReadAll(resp.Body)
	if err != nil {
		return fmt.Errorf("yourimageshare: reading response: %w", err)
	}

	var envelope apiEnvelope
	if jsonErr := json.Unmarshal(raw, &envelope); jsonErr != nil {
		return &APIError{Status: resp.StatusCode, Message: fmt.Sprintf("unexpected non-JSON response (HTTP %d)", resp.StatusCode)}
	}

	if resp.StatusCode < 200 || resp.StatusCode >= 300 || envelope.Type == "error" {
		message := envelope.Errors
		if message == "" {
			message = fmt.Sprintf("request failed (HTTP %d)", resp.StatusCode)
		}
		return &APIError{Status: resp.StatusCode, Message: message}
	}

	if out != nil {
		if err := json.Unmarshal(raw, out); err != nil {
			return fmt.Errorf("yourimageshare: decoding response: %w", err)
		}
	}
	return nil
}
