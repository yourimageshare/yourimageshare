defmodule YourImageShare.MixProject do
  use Mix.Project

  @version "1.0.0"
  @source_url "https://github.com/yourimageshare/yourimageshare"

  def project do
    [
      app: :yourimageshare,
      version: @version,
      elixir: "~> 1.14",
      start_permanent: Mix.env() == :prod,
      deps: deps(),
      description: description(),
      package: package(),
      name: "YourImageShare",
      source_url: @source_url,
      docs: [
        main: "readme",
        extras: ["README.md"]
      ]
    ]
  end

  def application do
    [
      extra_applications: [:logger]
    ]
  end

  defp deps do
    [
      {:req, "~> 0.5"},
      {:ex_doc, ">= 0.0.0", only: :dev, runtime: false}
    ]
  end

  defp description do
    "Official Elixir client for the YourImageShare upload API " <>
      "(https://yourimageshare.com/about/api) - upload, list, and delete files."
  end

  defp package do
    [
      licenses: ["MIT"],
      links: %{
        "GitHub" => @source_url <> "/tree/main/elixir",
        "Homepage" => "https://yourimageshare.com/about/api"
      },
      files: ~w(lib mix.exs README.md LICENSE)
    ]
  end
end
