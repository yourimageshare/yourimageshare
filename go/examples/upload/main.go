package main

import (
	"errors"
	"fmt"
	"os"

	yourimageshare "github.com/yourimageshare/yourimageshare/go"
)

func main() {
	client, err := yourimageshare.NewClient("YOUR_API_KEY")
	if err != nil {
		fmt.Fprintln(os.Stderr, err)
		os.Exit(1)
	}

	result, err := client.Upload("photo.jpg", nil)
	if err != nil {
		var apiErr *yourimageshare.APIError
		if errors.As(err, &apiErr) {
			fmt.Fprintf(os.Stderr, "%d: %s\n", apiErr.Status, apiErr.Message)
		} else {
			fmt.Fprintln(os.Stderr, err)
		}
		os.Exit(1)
	}

	fmt.Println(result.Direct)
}
