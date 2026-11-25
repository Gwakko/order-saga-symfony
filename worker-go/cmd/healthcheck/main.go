// Command healthcheck probes the local /readyz endpoint; exit 0 when ready, 1 otherwise (distroless has no shell or curl).
package main

import (
	"context"
	"fmt"
	"os"
	"time"

	"order-saga-symfony/worker-go/internal/app"
	"order-saga-symfony/worker-go/internal/config"
)

func main() { os.Exit(run()) }

func run() int {
	cfg, err := config.LoadHealth(os.LookupEnv)
	if err != nil {
		fmt.Fprintln(os.Stderr, err)

		return 1
	}

	hc, err := app.BuildHealthcheck(cfg)
	if err != nil {
		fmt.Fprintln(os.Stderr, err)

		return 1
	}

	ctx, cancel := context.WithTimeout(context.Background(), 3*time.Second)
	defer cancel()

	if err := hc.Check(ctx); err != nil {
		fmt.Fprintln(os.Stderr, err)

		return 1
	}

	return 0
}
