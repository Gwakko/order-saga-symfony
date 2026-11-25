# Go worker for InspectGatewayCharge (M9). Build context: ./worker-go
# Digests pinned 2026-11-25 (tag@digest); update both together.
FROM golang:1.27.3-alpine3.24@sha256:fa3d596898d9f126af7c01d14ee7597bab3f39cccf392ef36b69fe77cd681f18 AS build
WORKDIR /src

# Module download is a separate layer: it is rebuilt only when go.mod or go.sum change.
COPY go.mod go.sum ./
RUN go mod download

COPY cmd ./cmd
COPY internal ./internal
RUN CGO_ENABLED=0 go build -trimpath -ldflags="-s -w" -o /out/worker ./cmd/worker \
 && CGO_ENABLED=0 go build -trimpath -ldflags="-s -w" -o /out/healthcheck ./cmd/healthcheck

FROM gcr.io/distroless/static-debian12:nonroot@sha256:afa5c872c891853ca7fcf1f12c3edb23f7eeef36189728842dd51042ff57f7ab
COPY --from=build /out/worker /worker
COPY --from=build /out/healthcheck /healthcheck
USER nonroot:nonroot
EXPOSE 8090
# The healthcheck is defined in compose.yaml (there is no shell in this image).
ENTRYPOINT ["/worker"]
