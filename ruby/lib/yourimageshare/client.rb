require "net/http"
require "uri"
require "json"
require "securerandom"
require "stringio"

module YourImageShare
  DEFAULT_BASE_URL = "https://yourimageshare.com/api"
  # Files above this size are sent in pieces (one request can carry at most 100 MB).
  CHUNK_THRESHOLD = 90 * 1024 * 1024
  # Size of one piece of a chunked upload (the API accepts at most 5 MB).
  CHUNK_SIZE = 5 * 1024 * 1024

  # Talks to the YourImageShare upload API. Create one with
  # YourImageShare::Client.new(api_key). Get a key from the API tab at
  # https://yourimageshare.com/my-account.
  class Client
    def initialize(api_key, base_url: DEFAULT_BASE_URL, timeout: 30)
      raise ArgumentError, "api_key is required" if api_key.nil? || api_key.empty?

      @api_key = api_key
      @base_url = base_url
      @timeout = timeout
    end

    # Uploads a local file by path (up to 200 MB). expires_in (seconds, 60 to
    # 2,592,000 = 30 days) auto-deletes the upload later; nil means a
    # permanent upload. If your account already uploaded this exact file,
    # that upload is returned with duplicate = true - pass
    # allow_duplicate: true to store a new copy. Files over 90 MB are sent in
    # 5 MB pieces automatically; the block, if given, is called with
    # (bytes_sent, total) after each piece.
    def upload(file_path, expires_in: nil, allow_duplicate: false, &on_progress)
      File.open(file_path, "rb") do |f|
        upload_io(f, File.basename(file_path), expires_in: expires_in, allow_duplicate: allow_duplicate, &on_progress)
      end
    end

    # Uploads from any IO-like object (must respond to #read). filename
    # should include a real extension so the server can infer content type.
    def upload_io(io, filename, expires_in: nil, allow_duplicate: false, &on_progress)
      size = io_size(io)
      if size && size > CHUNK_THRESHOLD
        upload_id = send_chunks(io, size, &on_progress)
        return post_upload([["upload_id", upload_id], ["filename", filename]], expires_in, allow_duplicate)
      end

      # Net::HTTP streams IO form values in chunks rather than buffering
      # the whole file into memory.
      post_upload([["uploads", io, { filename: filename }]], expires_in, allow_duplicate)
    end

    # Uploads from a public http(s) link: the server downloads the file
    # itself (up to 200 MB).
    def upload_url(url, expires_in: nil, allow_duplicate: false)
      post_upload([["url", url]], expires_in, allow_duplicate)
    end

    # Returns your uploads, newest first, 50 per page. page < 2 fetches the
    # first page.
    def list(page = nil)
      uri = URI(@base_url)
      uri.query = URI.encode_www_form(page: page) if page && page > 1

      request = Net::HTTP::Get.new(uri)
      set_common_headers(request)

      ListResult.from_json(execute(uri, request))
    end

    # Removes one of your uploads by id. Raises APIError on failure.
    def delete(id)
      uri = URI("#{@base_url}/#{URI.encode_www_form_component(id)}")
      request = Net::HTTP::Delete.new(uri)
      set_common_headers(request)

      execute(uri, request)
      nil
    end

    private

    def post_upload(form, expires_in, allow_duplicate)
      uri = URI(@base_url)
      request = Net::HTTP::Post.new(uri)
      set_common_headers(request)
      form << ["expires_in", expires_in.to_s] if expires_in && expires_in > 0
      form << ["allow_duplicate", "1"] if allow_duplicate
      request.set_form(form, "multipart/form-data")

      body = execute(uri, request, [@timeout, 180].max)
      UploadResult.from_json(body["data"] || {})
    end

    def send_chunks(io, size)
      upload_id = SecureRandom.hex(16)
      total = (size + CHUNK_SIZE - 1) / CHUNK_SIZE
      sent = 0
      uri = URI("#{@base_url.chomp("/")}/chunk")
      total.times do |index|
        piece = io.read(CHUNK_SIZE)
        attempt = 0
        begin
          attempt += 1
          request = Net::HTTP::Post.new(uri)
          set_common_headers(request)
          request.set_form([["upload_id", upload_id], ["index", index.to_s], ["total", total.to_s],
                            ["chunk", StringIO.new(piece), { filename: "piece" }]], "multipart/form-data")
          execute(uri, request)
        rescue APIError, IOError, SystemCallError, Timeout::Error => e
          # network errors and 5xx are retried; anything the server refused (4xx) is final
          status = e.respond_to?(:status) ? e.status.to_i : 0
          retry if attempt < 3 && !(status >= 400 && status < 500)
          raise
        end
        sent += piece.bytesize
        yield(sent, size) if block_given?
      end
      upload_id
    end

    def io_size(io)
      return io.size - io.pos if io.respond_to?(:size) && io.respond_to?(:pos)
      return File.size(io.path) - io.pos if io.respond_to?(:path) && io.respond_to?(:pos)
      nil
    rescue StandardError
      nil
    end

    def set_common_headers(request)
      request["X-API-Key"] = @api_key
      request["User-Agent"] = "yourimageshare-ruby/#{VERSION}"
    end

    def execute(uri, request, read_timeout = @timeout)
      response = Net::HTTP.start(uri.host, uri.port, use_ssl: uri.scheme == "https",
                                  open_timeout: @timeout, read_timeout: read_timeout, write_timeout: read_timeout) do |http|
        http.request(request)
      end

      envelope = begin
        JSON.parse(response.body.to_s)
      rescue JSON::ParserError
        raise APIError.new(response.code.to_i, "unexpected non-JSON response (HTTP #{response.code})")
      end

      status = response.code.to_i
      if status < 200 || status >= 300 || envelope["type"] == "error"
        message = envelope["errors"]
        message = "request failed (HTTP #{status})" if message.nil? || message.empty?
        raise APIError.new(status, message)
      end

      envelope
    end
  end
end
