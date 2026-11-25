package activity_test

import (
	"context"

	"go.temporal.io/sdk/worker"
)

func testWorkerOptions(bg context.Context) worker.Options {
	return worker.Options{BackgroundActivityContext: bg}
}
