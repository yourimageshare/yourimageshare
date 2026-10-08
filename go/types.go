package yourimageshare

// UploadResult is the response shape for a successful upload - same
// fields as the JS/Python/PHP SDKs' UploadResult.
type UploadResult struct {
	ID   string `json:"id"`
	Type string `json:"type"`
	// Path is the storage URL of the file as uploaded. It can change
	// shortly afterwards when the file is converted (WebP/MP4) - store Src.
	Path string `json:"path"`
	// Src is the permanent direct file URL - it always opens the current file.
	Src    string `json:"src"`
	Direct string `json:"direct"`
	// Thumb is a 280 px wide WebP thumbnail (a video's first frame), or nil.
	Thumb  *string `json:"thumb"`
	Width  *int    `json:"width"`
	Height *int    `json:"height"`
	// Size is the file size in bytes as stored.
	Size *int64 `json:"size"`
	// Locked is true if the upload is password-protected.
	Locked bool `json:"locked"`
	// Visibility is "private" (file only), "unlisted" (page for anyone
	// with the link) or "public" (listed).
	Visibility  string  `json:"visibility"`
	Title       *string `json:"title"`
	Description *string `json:"description"`
	ExpiresAt   *string `json:"expires_at"`
	// Duplicate is true if your account had already uploaded this exact
	// file and that upload was returned instead of a new one.
	Duplicate bool `json:"duplicate"`
	// DeleteURL (new uploads only) deletes the upload without an API key.
	// It is shown once.
	DeleteURL string `json:"delete_url"`
}

// ListedUpload is one row of a List() result.
type ListedUpload struct {
	ID          string  `json:"id"`
	Type        string  `json:"type"`
	Title       *string `json:"title"`
	Path        string  `json:"path"`
	Src         string  `json:"src"`
	Direct      string  `json:"direct"`
	Thumb       *string `json:"thumb"`
	Width       *int    `json:"width"`
	Height      *int    `json:"height"`
	Size        *int64  `json:"size"`
	Locked      bool    `json:"locked"`
	Visibility  string  `json:"visibility"`
	Description *string `json:"description"`
	ExpiresAt   *string `json:"expires_at"`
	CreatedAt   string  `json:"created_at"`
}

// ListMeta carries the pagination info for a List() result.
type ListMeta struct {
	CurrentPage int `json:"current_page"`
	LastPage    int `json:"last_page"`
	Total       int `json:"total"`
}

// ListResult is the response shape for List().
type ListResult struct {
	Data []ListedUpload `json:"data"`
	Meta ListMeta       `json:"meta"`
}

// apiEnvelope is the raw `{"type": "success"|"error", ...}` wrapper every
// endpoint returns - unexported, callers only ever see the typed results
// above or an *APIError.
type apiEnvelope struct {
	Type   string `json:"type"`
	Errors string `json:"errors"`
}
