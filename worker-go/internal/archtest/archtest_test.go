package archtest_test

import (
	"go/ast"
	"go/parser"
	"go/token"
	"io/fs"
	"path/filepath"
	"sort"
	"strconv"
	"strings"
	"testing"
)

const modulePath = "order-saga-symfony/worker-go"

// goFile is one parsed non-test source file.
type goFile struct {
	path string // relative to worker-go, slash separated
	file *ast.File
}

// sources parses the non-test .go files below worker-go/<dirs...>. Tests run with the package directory as cwd.
func sources(t *testing.T, dirs ...string) []goFile {
	t.Helper()

	var out []goFile
	fset := token.NewFileSet()

	for _, dir := range dirs {
		root := filepath.Join("..", "..", filepath.FromSlash(dir))
		err := filepath.WalkDir(root, func(path string, d fs.DirEntry, err error) error {
			if err != nil {
				return err
			}
			if d.IsDir() || !strings.HasSuffix(path, ".go") || strings.HasSuffix(path, "_test.go") {
				return nil
			}

			f, err := parser.ParseFile(fset, path, nil, parser.ParseComments)
			if err != nil {
				return err
			}
			rel, _ := filepath.Rel(filepath.Join("..", ".."), path)
			out = append(out, goFile{path: filepath.ToSlash(rel), file: f})

			return nil
		})
		if err != nil {
			t.Fatalf("scan %s: %v", dir, err)
		}
	}

	if len(out) == 0 {
		t.Fatalf("no sources found under %v: the test would pass vacuously", dirs)
	}

	return out
}

func imports(f goFile) []string {
	var paths []string
	for _, spec := range f.file.Imports {
		p, _ := strconv.Unquote(spec.Path.Value)
		paths = append(paths, p)
	}

	return paths
}

// Group: T-GO-14
func TestNoStructTagsInDomainAndUseCase(t *testing.T) {
	for _, f := range sources(t, "internal/domain", "internal/usecase") {
		ast.Inspect(f.file, func(n ast.Node) bool {
			if field, ok := n.(*ast.Field); ok && field.Tag != nil {
				t.Errorf("%s: struct tag %s (tags belong to boundary DTOs)", f.path, field.Tag.Value)
			}

			return true
		})
	}
}

// Group: T-GO-14
func TestEnvIsReadOnlyByConfigAndCmd(t *testing.T) {
	var scanned []string
	for _, f := range sources(t, "internal") {
		scanned = append(scanned, f.path)
		if strings.HasPrefix(f.path, "internal/config/") || strings.HasPrefix(f.path, "cmd/") {
			continue
		}

		ast.Inspect(f.file, func(n ast.Node) bool {
			sel, ok := n.(*ast.SelectorExpr)
			if !ok {
				return true
			}
			pkg, ok := sel.X.(*ast.Ident)
			if ok && pkg.Name == "os" && (sel.Sel.Name == "Getenv" || sel.Sel.Name == "LookupEnv" || sel.Sel.Name == "Environ") {
				t.Errorf("%s: os.%s outside internal/config and cmd", f.path, sel.Sel.Name)
			}

			return true
		})
	}

	sort.Strings(scanned)
	t.Logf("scanned %d files", len(scanned))
}

// Group: T-GO-14
func TestDomainAndUseCaseDoNotImportInfrastructure(t *testing.T) {
	forbidden := []string{
		"go.temporal.io",
		"net/http",
		"database/sql",
		"os",
		modulePath + "/internal/app",
		modulePath + "/internal/adapter",
		modulePath + "/internal/config",
		modulePath + "/internal/contracttest",
	}

	for _, f := range sources(t, "internal/domain", "internal/usecase") {
		for _, imp := range imports(f) {
			for _, bad := range forbidden {
				if imp == bad || strings.HasPrefix(imp, bad+"/") {
					t.Errorf("%s imports %s", f.path, imp)
				}
			}
		}
	}

	// The domain additionally does not know the use case.
	for _, f := range sources(t, "internal/domain") {
		for _, imp := range imports(f) {
			if strings.HasPrefix(imp, modulePath+"/internal/usecase") {
				t.Errorf("%s imports %s", f.path, imp)
			}
		}
	}
}

// Group: T-GO-14
func TestAdaptersDoNotImportTemporalOrEachOtherAcrossGateways(t *testing.T) {
	// gateway adapters talk to the use case ports and the domain only.
	for _, f := range sources(t, "internal/adapter/gateway", "internal/adapter/contract", "internal/adapter/clock") {
		for _, imp := range imports(f) {
			if strings.HasPrefix(imp, "go.temporal.io") || imp == modulePath+"/internal/app" {
				t.Errorf("%s imports %s", f.path, imp)
			}
		}
	}
}

// Group: T-GO-15
func TestNoFloatingPointInDomainAndUseCase(t *testing.T) {
	for _, f := range sources(t, "internal/domain", "internal/usecase") {
		ast.Inspect(f.file, func(n ast.Node) bool {
			switch v := n.(type) {
			case *ast.Ident:
				if v.Name == "float32" || v.Name == "float64" {
					t.Errorf("%s: %s (money is int64 minor units)", f.path, v.Name)
				}
			case *ast.BasicLit:
				if v.Kind == token.FLOAT {
					t.Errorf("%s: float literal %s", f.path, v.Value)
				}
			}

			return true
		})
	}
}

// Group: T-GO-15
func TestNoFloatingPointInTheGatewayAdapter(t *testing.T) {
	for _, f := range sources(t, "internal/adapter/gateway/httpgateway", "internal/adapter/contract") {
		ast.Inspect(f.file, func(n ast.Node) bool {
			if id, ok := n.(*ast.Ident); ok && (id.Name == "float32" || id.Name == "float64") {
				t.Errorf("%s: %s", f.path, id.Name)
			}

			return true
		})
	}
}

// The scanner itself must detect what it claims to detect.
func TestScannerDetectsViolations(t *testing.T) {
	src := `package x
import "net/http"
type T struct { A float64 ` + "`json:\"a\"`" + ` }
func f() { _ = http.StatusOK }`

	f, err := parser.ParseFile(token.NewFileSet(), "x.go", src, 0)
	if err != nil {
		t.Fatal(err)
	}

	var tags, floats int
	ast.Inspect(f, func(n ast.Node) bool {
		if field, ok := n.(*ast.Field); ok && field.Tag != nil {
			tags++
		}
		if id, ok := n.(*ast.Ident); ok && id.Name == "float64" {
			floats++
		}

		return true
	})

	if tags != 1 || floats != 1 || imports(goFile{file: f})[0] != "net/http" {
		t.Fatalf("scanner blind: tags=%d floats=%d", tags, floats)
	}
}
