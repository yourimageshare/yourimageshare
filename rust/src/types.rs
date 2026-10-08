use serde::Deserialize;

/// Response shape for a successful upload - same fields as the
/// JS/Python/PHP/Go SDKs' upload result.
#[derive(Debug, Clone, Deserialize)]
pub struct UploadResult {
    pub id: String,
    #[serde(rename = "type")]
    pub kind: String,
    /// Storage URL of the file as uploaded. It can change shortly afterwards
    /// when the file is converted (WebP/MP4) - store `src`.
    pub path: String,
    /// Permanent direct file URL - always opens the current file.
    pub src: String,
    pub direct: String,
    /// 280 px wide WebP thumbnail (a video's first frame).
    #[serde(default)]
    pub thumb: Option<String>,
    #[serde(default)]
    pub width: Option<u32>,
    #[serde(default)]
    pub height: Option<u32>,
    /// File size in bytes as stored.
    #[serde(default)]
    pub size: Option<u64>,
    /// True if the upload is password-protected.
    #[serde(default)]
    pub locked: bool,
    pub expires_at: Option<String>,
    /// True if your account had already uploaded this exact file and that
    /// upload was returned instead of a new one.
    #[serde(default)]
    pub duplicate: bool,
}

/// One row of a `list()` result.
#[derive(Debug, Clone, Deserialize)]
pub struct ListedUpload {
    pub id: String,
    #[serde(rename = "type")]
    pub kind: String,
    pub title: Option<String>,
    pub path: String,
    pub src: String,
    pub direct: String,
    #[serde(default)]
    pub thumb: Option<String>,
    #[serde(default)]
    pub width: Option<u32>,
    #[serde(default)]
    pub height: Option<u32>,
    #[serde(default)]
    pub size: Option<u64>,
    #[serde(default)]
    pub locked: bool,
    pub expires_at: Option<String>,
    pub created_at: String,
}

/// Pagination info for a `list()` result.
#[derive(Debug, Clone, Deserialize)]
pub struct ListMeta {
    pub current_page: u32,
    pub last_page: u32,
    pub total: u32,
}

/// Response shape for `list()`.
#[derive(Debug, Clone, Deserialize)]
pub struct ListResult {
    pub data: Vec<ListedUpload>,
    pub meta: ListMeta,
}

/// The raw `{"type": "success"|"error", ...}` wrapper every endpoint
/// returns.
#[derive(Debug, Deserialize)]
pub(crate) struct ApiEnvelope {
    #[serde(rename = "type")]
    pub kind: String,
    #[serde(default)]
    pub errors: String,
}
