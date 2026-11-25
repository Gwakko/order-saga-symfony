// Package clock is the system clock adapter. It always reports UTC.
package clock

import "time"

// System reads the wall clock.
type System struct{}

// New returns the system clock. It owns no configuration.
func New() System { return System{} }

// Now returns the current time in UTC.
func (System) Now() time.Time { return time.Now().UTC() }
