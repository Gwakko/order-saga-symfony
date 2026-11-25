// Package contracttest gives tests access to the golden files of contracts/inspect-gateway-charge/v1 in the repository root.
// It is test support only; production code does not import it.
package contracttest

import (
	"bytes"
	"encoding/json"
	"os"
	"path/filepath"
	"runtime"
	"testing"
)

// Dir returns the absolute path of the contract directory, found relative to this source file
// (worker-go/internal/contracttest -> repository root). In the go-toolchain container the repository is mounted at /src.
func Dir(tb testing.TB) string {
	tb.Helper()

	_, file, _, ok := runtime.Caller(0)
	if !ok {
		tb.Fatal("cannot locate contracttest source file")
	}

	dir := filepath.Join(filepath.Dir(file), "..", "..", "..", "contracts", "inspect-gateway-charge", "v1")
	if _, err := os.Stat(dir); err != nil {
		tb.Fatalf("contract directory not found: %v", err)
	}

	return dir
}

// Read returns the bytes of one contract file.
func Read(tb testing.TB, name string) []byte {
	tb.Helper()

	data, err := os.ReadFile(filepath.Join(Dir(tb), name))
	if err != nil {
		tb.Fatalf("read contract file %s: %v", name, err)
	}

	return data
}

// Decode parses JSON into generic values with numbers kept as json.Number, so that structural comparison is exact.
func Decode(tb testing.TB, data []byte) any {
	tb.Helper()

	dec := json.NewDecoder(bytes.NewReader(data))
	dec.UseNumber()

	var v any
	if err := dec.Decode(&v); err != nil {
		tb.Fatalf("decode JSON: %v", err)
	}

	return v
}
