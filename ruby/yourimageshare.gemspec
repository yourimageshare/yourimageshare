require_relative "lib/yourimageshare/version"

Gem::Specification.new do |spec|
  spec.name          = "yourimageshare"
  spec.version       = YourImageShare::VERSION
  spec.authors       = ["YourImageShare"]
  spec.email         = ["support@yourimageshare.com"]

  spec.summary       = "Official Ruby client for the YourImageShare upload API"
  spec.description   = "Upload, list, and delete files through the YourImageShare " \
                        "(https://yourimageshare.com) JSON/REST API. Zero gem " \
                        "dependencies - standard library only."
  spec.homepage      = "https://yourimageshare.com/about/api"
  spec.license       = "MIT"
  spec.required_ruby_version = ">= 2.7.0"

  spec.metadata["homepage_uri"]    = spec.homepage
  spec.metadata["source_code_uri"] = "https://github.com/yourimageshare/yourimageshare/tree/main/ruby"
  spec.metadata["changelog_uri"]   = "https://github.com/yourimageshare/yourimageshare/tree/main/ruby/README.md"

  spec.files = Dir["lib/**/*.rb"] + ["README.md", "LICENSE"]
  spec.require_paths = ["lib"]
end
