module YourImageShare
  # Response shape for a successful upload - same fields as the
  # Go/JS/Python/PHP SDKs' UploadResult.
  #
  # path is the storage URL as uploaded and can change shortly afterwards
  # when the file is converted (WebP/MP4) - store src, the permanent link.
  # thumb is a 280 px WebP thumbnail (or nil); size is in bytes; duplicate is
  # true when your account already had this exact file and that upload was
  # returned.
  UploadResult = Struct.new(:id, :type, :path, :src, :direct, :thumb, :width, :height, :size, :locked,
                            :expires_at, :duplicate, keyword_init: true) do
    def self.from_json(h)
      new(id: h["id"], type: h["type"], path: h["path"], src: h["src"], direct: h["direct"],
          thumb: h["thumb"], width: h["width"], height: h["height"], size: h["size"],
          locked: h["locked"] == true, expires_at: h["expires_at"], duplicate: h["duplicate"] == true)
    end
  end

  # One row of a #list result.
  ListedUpload = Struct.new(:id, :type, :title, :path, :src, :direct, :thumb, :width, :height, :size, :locked,
                            :expires_at, :created_at, keyword_init: true) do
    def self.from_json(h)
      new(id: h["id"], type: h["type"], title: h["title"], path: h["path"],
          src: h["src"], direct: h["direct"], thumb: h["thumb"], width: h["width"], height: h["height"],
          size: h["size"], locked: h["locked"] == true, expires_at: h["expires_at"],
          created_at: h["created_at"])
    end
  end

  # Pagination info for a #list result.
  ListMeta = Struct.new(:current_page, :last_page, :total, keyword_init: true) do
    def self.from_json(h)
      new(current_page: h["current_page"], last_page: h["last_page"], total: h["total"])
    end
  end

  # Response shape for #list.
  ListResult = Struct.new(:data, :meta, keyword_init: true) do
    def self.from_json(h)
      new(data: (h["data"] || []).map { |row| ListedUpload.from_json(row) },
          meta: ListMeta.from_json(h["meta"] || {}))
    end
  end
end
