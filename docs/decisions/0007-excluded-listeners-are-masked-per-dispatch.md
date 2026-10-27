# 0007. Excluded listeners are masked per dispatch

## Context

Some listeners must not run for writes through the other side, such as a journal of native writes only. The bridge dispatches mirrored events through the application's dispatcher, which runs every listener of an event. A native write inside a mirrored listener raises the same event names.

## Decision

`EventReplayer::mask()` swaps an event's listeners for wrappers for the length of one dispatch, then `EventReplayer::unmask()` restores them. A wrapper skips its listener only when the payload's subject is the instance under replay, recorded in an `Exclusion`.

## Consequences

- A native write inside a mirrored listener still runs the excluded listener.
- The masking works through a decorating dispatcher, and acts on the real dispatcher behind an `EventFake`. The dispatch itself goes through the model's dispatcher, the fake included, so a faked event is recorded and runs no listener.
- Only a listener named by a class string, or by a class or object and a method, can be excluded. A closure cannot.
