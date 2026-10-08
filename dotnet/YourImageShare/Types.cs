using System;
using System.Collections.Generic;
using System.Text.Json.Serialization;

namespace YourImageShare
{
    /// <summary>Response shape for a successful upload - same fields as the JS/Python/PHP/Go SDKs' UploadResult.</summary>
    public sealed class UploadResult
    {
        [JsonPropertyName("id")]
        public string Id { get; set; } = string.Empty;

        [JsonPropertyName("type")]
        public string Type { get; set; } = string.Empty;

        /// <summary>Storage URL of the file as uploaded. It can change shortly afterwards when the file is converted (WebP/MP4) - store <see cref="Src"/>.</summary>
        [JsonPropertyName("path")]
        public string Path { get; set; } = string.Empty;

        /// <summary>Permanent direct file URL - always opens the current file.</summary>
        [JsonPropertyName("src")]
        public string Src { get; set; } = string.Empty;

        [JsonPropertyName("direct")]
        public string Direct { get; set; } = string.Empty;

        /// <summary>280 px wide WebP thumbnail (a video's first frame), or null.</summary>
        [JsonPropertyName("thumb")]
        public string? Thumb { get; set; }

        [JsonPropertyName("width")]
        public int? Width { get; set; }

        [JsonPropertyName("height")]
        public int? Height { get; set; }

        /// <summary>File size in bytes as stored.</summary>
        [JsonPropertyName("size")]
        public long? Size { get; set; }

        /// <summary>True if the upload is password-protected.</summary>
        [JsonPropertyName("locked")]
        public bool Locked { get; set; }

        /// <summary>"private" (file only), "unlisted" (page for anyone with the link) or "public" (listed).</summary>
        [JsonPropertyName("visibility")]
        public string Visibility { get; set; } = "unlisted";

        [JsonPropertyName("description")]
        public string? Description { get; set; }

        [JsonPropertyName("expires_at")]
        public string? ExpiresAt { get; set; }

        /// <summary>True if your account had already uploaded this exact file and that upload was returned.</summary>
        [JsonPropertyName("duplicate")]
        public bool Duplicate { get; set; }

        [JsonPropertyName("title")]
        public string? Title { get; set; }

        /// <summary>New uploads only: a private link that deletes the upload without an API key. Shown once.</summary>
        [JsonPropertyName("delete_url")]
        public string? DeleteUrl { get; set; }
    }

    /// <summary>One row of a <see cref="YourImageShareClient.ListAsync"/> result.</summary>
    public sealed class ListedUpload
    {
        [JsonPropertyName("id")]
        public string Id { get; set; } = string.Empty;

        [JsonPropertyName("type")]
        public string Type { get; set; } = string.Empty;

        [JsonPropertyName("title")]
        public string? Title { get; set; }

        [JsonPropertyName("path")]
        public string Path { get; set; } = string.Empty;

        [JsonPropertyName("src")]
        public string Src { get; set; } = string.Empty;

        [JsonPropertyName("direct")]
        public string Direct { get; set; } = string.Empty;

        /// <summary>280 px wide WebP thumbnail (a video's first frame), or null.</summary>
        [JsonPropertyName("thumb")]
        public string? Thumb { get; set; }

        [JsonPropertyName("width")]
        public int? Width { get; set; }

        [JsonPropertyName("height")]
        public int? Height { get; set; }

        /// <summary>File size in bytes as stored.</summary>
        [JsonPropertyName("size")]
        public long? Size { get; set; }

        /// <summary>True if the upload is password-protected.</summary>
        [JsonPropertyName("locked")]
        public bool Locked { get; set; }

        /// <summary>"private" (file only), "unlisted" (page for anyone with the link) or "public" (listed).</summary>
        [JsonPropertyName("visibility")]
        public string Visibility { get; set; } = "unlisted";

        [JsonPropertyName("description")]
        public string? Description { get; set; }

        [JsonPropertyName("expires_at")]
        public string? ExpiresAt { get; set; }

        [JsonPropertyName("created_at")]
        public string CreatedAt { get; set; } = string.Empty;
    }

    /// <summary>Pagination info for a <see cref="YourImageShareClient.ListAsync"/> result.</summary>
    public sealed class ListMeta
    {
        [JsonPropertyName("current_page")]
        public int CurrentPage { get; set; }

        [JsonPropertyName("last_page")]
        public int LastPage { get; set; }

        [JsonPropertyName("total")]
        public int Total { get; set; }
    }

    /// <summary>Response shape for <see cref="YourImageShareClient.ListAsync"/>.</summary>
    public sealed class ListResult
    {
        [JsonPropertyName("data")]
        public List<ListedUpload> Data { get; set; } = new List<ListedUpload>();

        [JsonPropertyName("meta")]
        public ListMeta Meta { get; set; } = new ListMeta();
    }

    /// <summary>Optional parameters for UploadAsync.</summary>
    public sealed class UploadOptions
    {
        /// <summary>Auto-deletes the upload after this many seconds (60 to 2,592,000 = 30 days). Null means a permanent upload.</summary>
        public int? ExpiresIn { get; set; }

        /// <summary>Store a new copy even if your account already uploaded this exact file (otherwise that upload is returned with Duplicate = true).</summary>
        public bool AllowDuplicate { get; set; }

        /// <summary>Called after each piece of a chunked upload (files over 90 MB) with the bytes sent so far and the total.</summary>
        public Action<long, long>? OnProgress { get; set; }

        /// <summary>"unlisted" (server default: file and page work for anyone with the link), "private" (file only, no page for others) or "public" (listed). Null means the default.</summary>
        public string? Visibility { get; set; }

        /// <summary>Up to 90 characters.</summary>
        public string? Title { get; set; }

        /// <summary>Up to 500 characters.</summary>
        public string? Description { get; set; }
    }

    /// <summary>Fields <see cref="YourImageShareClient.UpdateAsync"/> changes; null leaves a field as it is, an empty string clears a title/description.</summary>
    public sealed class UpdateOptions
    {
        [JsonPropertyName("visibility")]
        public string? Visibility { get; set; }

        [JsonPropertyName("title")]
        public string? Title { get; set; }

        [JsonPropertyName("description")]
        public string? Description { get; set; }
    }

    /// <summary>The raw `{"type": "success"|"error", ...}` wrapper every endpoint returns - only used to detect an error response before re-parsing the raw body into the shape a caller actually needs.</summary>
    internal sealed class ApiEnvelope
    {
        [JsonPropertyName("type")]
        public string Type { get; set; } = string.Empty;

        [JsonPropertyName("errors")]
        public string? Errors { get; set; }
    }
}
