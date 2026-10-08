defmodule YourImageShare.UploadResult do
  @moduledoc "Response shape for a successful upload - same fields as every other official SDK."

  @type t :: %__MODULE__{
          id: String.t(),
          type: String.t(),
          path: String.t(),
          src: String.t(),
          direct: String.t(),
          thumb: String.t() | nil,
          width: non_neg_integer() | nil,
          height: non_neg_integer() | nil,
          size: non_neg_integer() | nil,
          locked: boolean(),
          visibility: String.t() | nil,
          title: String.t() | nil,
          description: String.t() | nil,
          expires_at: String.t() | nil,
          duplicate: boolean(),
          delete_url: String.t() | nil
        }

  # path is the storage URL as uploaded and can change shortly afterwards when the file is converted
  # (WebP/MP4) - store src, the permanent link. thumb is a 280 px WebP thumbnail; duplicate is true when
  # the account already had this exact file and that upload was returned.
  defstruct [
    :id,
    :type,
    :path,
    :src,
    :direct,
    :thumb,
    :width,
    :height,
    :size,
    :visibility,
    :title,
    :description,
    :expires_at,
    :delete_url,
    locked: false,
    duplicate: false
  ]

  @doc false
  def from_map(map) do
    %__MODULE__{
      id: map["id"],
      type: map["type"],
      path: map["path"],
      src: map["src"],
      direct: map["direct"],
      thumb: map["thumb"],
      width: map["width"],
      height: map["height"],
      size: map["size"],
      locked: map["locked"] == true,
      visibility: map["visibility"],
      title: map["title"],
      description: map["description"],
      expires_at: map["expires_at"],
      duplicate: map["duplicate"] == true,
      delete_url: map["delete_url"]
    }
  end
end

defmodule YourImageShare.ListedUpload do
  @moduledoc "One row of a `list/2` result."

  @type t :: %__MODULE__{
          id: String.t(),
          type: String.t(),
          title: String.t() | nil,
          path: String.t(),
          src: String.t(),
          direct: String.t(),
          thumb: String.t() | nil,
          width: non_neg_integer() | nil,
          height: non_neg_integer() | nil,
          size: non_neg_integer() | nil,
          locked: boolean(),
          visibility: String.t() | nil,
          description: String.t() | nil,
          expires_at: String.t() | nil,
          created_at: String.t()
        }

  defstruct [
    :id,
    :type,
    :title,
    :path,
    :src,
    :direct,
    :thumb,
    :width,
    :height,
    :size,
    :visibility,
    :description,
    :expires_at,
    :created_at,
    locked: false
  ]

  @doc false
  def from_map(map) do
    %__MODULE__{
      id: map["id"],
      type: map["type"],
      title: map["title"],
      path: map["path"],
      src: map["src"],
      direct: map["direct"],
      thumb: map["thumb"],
      width: map["width"],
      height: map["height"],
      size: map["size"],
      locked: map["locked"] == true,
      visibility: map["visibility"],
      description: map["description"],
      expires_at: map["expires_at"],
      created_at: map["created_at"]
    }
  end
end

defmodule YourImageShare.ListMeta do
  @moduledoc "Pagination info for a `list/2` result."

  @type t :: %__MODULE__{current_page: integer(), last_page: integer(), total: integer()}

  defstruct [:current_page, :last_page, :total]

  @doc false
  def from_map(map) do
    %__MODULE__{
      current_page: map["current_page"],
      last_page: map["last_page"],
      total: map["total"]
    }
  end
end

defmodule YourImageShare.ListResult do
  @moduledoc "Response shape for `list/2`."

  alias YourImageShare.{ListedUpload, ListMeta}

  @type t :: %__MODULE__{data: [ListedUpload.t()], meta: ListMeta.t()}

  defstruct [:data, :meta]

  @doc false
  def from_map(map) do
    %__MODULE__{
      data: Enum.map(map["data"] || [], &ListedUpload.from_map/1),
      meta: ListMeta.from_map(map["meta"] || %{})
    }
  end
end
