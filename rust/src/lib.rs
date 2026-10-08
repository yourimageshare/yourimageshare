//! Official Rust client for the YourImageShare upload API
//! (<https://yourimageshare.com/about/api>). Mirrors the existing JS
//! (npm), Python (PyPI), PHP (Packagist), and Go SDKs - same method
//! names, same result shapes, same error shape - just idiomatic Rust on
//! top (methods return `Result<T, ApiError>`).
//!
//! # Example
//!
//! ```no_run
//! use yourimageshare::Client;
//!
//! let client = Client::new("YOUR_API_KEY");
//! let result = client.upload("photo.jpg", None).unwrap();
//! println!("{}", result.direct); // https://yourimageshare.com/ib/aB3xY9qRz1
//! ```

mod client;
mod error;
mod types;

pub use client::{Client, UpdateOptions, UploadOptions, Visibility, DEFAULT_BASE_URL};
pub use error::ApiError;
pub use types::{ListMeta, ListResult, ListedUpload, UploadResult};
