// Command worker runs the InspectGatewayCharge activity worker.
package main

import (
	"context"
	"fmt"
	"os"
	"os/signal"
	"syscall"

	"order-saga-symfony/worker-go/internal/app"
	"order-saga-symfony/worker-go/internal/config"
)

func main() { os.Exit(run()) }

func run() int {
	cfg, err := config.Load(os.LookupEnv)
	if err != nil {
		fmt.Fprintf(os.Stderr, "invalid configuration:\n%v\n", err)

		return 2
	}

	logger := app.NewLogger(cfg.Log, os.Stdout)

	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer stop()

	w, closeApp, err := app.BuildWorker(ctx, cfg, logger)
	if err != nil {
		logger.Error("build worker", "error", err.Error())

		return 1
	}
	defer closeApp()

	if err := w.Run(ctx); err != nil {
		logger.Error("worker stopped with error", "error", err.Error())

		return 1
	}

	return 0
}
