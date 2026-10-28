# 0007. Excluded listeners are masked per dispatch

## Context

Some listeners must not run for writes through the other side, such as a journal of native writes only. The bridge dispatches mirrored events through the application's dispatcher, which runs every listener of an event. A native write inside a mirrored listener raises the same event names.

## Decision

For the length of one dispatch, the replayer swaps an event's listeners for wrappers, then restores them, through `EventReplayer::mask()` and `EventReplayer::unmask()`. A wrapper skips its listener only when the payload's subject is the instance under replay, recorded in an `Exclusion`. The private `EventReplayer::maskWildcards()` and `EventReplayer::unmaskWildcards()` do the same for each wildcard registration that matches the event, such as `eloquent.updated: *`.

## Consequences

- A native write inside a mirrored listener still runs the excluded listener.
- The masking works through a decorating dispatcher, and acts on the real dispatcher behind an `EventFake`. The dispatch itself goes through the model's dispatcher, the fake included, so a faked event is recorded and runs no listener.
- Laravel keeps wildcard listeners in a protected property that no method lists, so the wildcard masking reads and writes it through reflection. It needs an `Illuminate\Events\Dispatcher` for that, so a decorating dispatcher's wildcard listeners stay unmasked. The wrappers replace the listeners in place, and a wildcard listener still receives the event name and the payload.
- Only a listener named by a class string, or by a class or object and a method, can be excluded. A closure cannot.
