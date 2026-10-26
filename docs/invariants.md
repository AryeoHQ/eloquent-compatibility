# Invariants

Each rule below holds today, and a change must keep it. Each names the code that must agree and the tests that pin it. Where no test pins a rule, the entry says so: a change there needs a reviewer's eye, or a new test.

## INV-1: Locks follow one order

**Rule.** The event bridge and the triggers lock the primary row before the retained row.

**Why.** The bridge locks before the statement, and the trigger locks the same rows again. Two writers that take locks in one order cannot deadlock on these rows.

**Code that must agree.** `RecordRole::lockOrder()` puts primary records first, previous records second and dependent records last. `AffectedRecords::snapshots()` sorts by it, then by lock table and key. `KeyTranslator::previousRecord()` makes a previous record lock its retained table. `Compiler::updateFunction()` and `Compiler::deleteFunction()` call `Compiler::lockPrimary()` before `Compiler::loadPrevious()`.

**Pinned by.** No test pins the order.

## INV-2: One expression locates a dependent row

**Rule.** The view, the update trigger and the event bridge pick a dependent row with the same subquery, from `ResolvedMap::locate()`.

**Why.** The bridge must mirror the row the view reads and the trigger writes. Two copies of the condition could pick two rows.

**Code that must agree.** `ResolvedMap::locate()`, called by `Compiler::read()`, `Compiler::updateDependent()` and `KeyTranslator::currentKeys()`.

**Pinned by.** `DependentCurrentModelTest::a_previous_model_write_mirrors_only_the_designated_dependent_row()` and `DependentCurrentModelTest::the_previous_model_reads_no_dependent_row_once_its_designated_row_is_no_longer_readable_and_reads_no_other()`.

## INV-3: The view's own writes skip this map's current-side triggers

**Rule.** The insert, update and delete triggers set the applying flag to the map's name around their writes. The primary insert, primary delete and designate triggers return early while it holds that name.

**Why.** An insert through the view writes the primary row itself. Without the flag, the primary insert trigger would add a second retained row. The flag holds the map's name, so another map over the same primary table still reacts.

**Code that must agree.** `Setting::Applying` and `Setting::for()`. `Compiler::applying()` in `Compiler::insertFunction()`, `Compiler::updateFunction()` and `Compiler::deleteFunction()`. `Compiler::skipWhileApplying()` in `Compiler::primaryInsertFunction()`, `Compiler::primaryDeleteFunction()` and `Compiler::designateFunction()`. The flag is transaction-local, so it cannot outlive the transaction.

**Pinned by.** `InstallerTest::it_inserts_one_record_into_every_table_and_returns_the_previous_model_key()` asserts one retained row. `InstallerTest::it_keeps_two_previous_models_over_one_current_record_in_step()` asserts that the other map reacts.

## INV-4: Every database refusal is CM001

**Rule.** Every refusal the compiled SQL raises uses SQLSTATE CM001, with a message, a detail and a hint.

**Why.** PHP recognises a refusal by that one code. It splits the three parts into what was refused, why, and what to do instead.

**Code that must agree.** `SqlState::Refused`, `SqlFragments::refuse()` and `SqlFragments::raise()`. Every refusal in `Compiler` calls `SqlFragments::refuse()`: in `Compiler::updateFunction()`, `Compiler::notNullChecks()`, `Compiler::updateDependent()`, `Compiler::missing()` and `Compiler::refuseFunction()`. `CompatibilityWriteRefused::isRefusal()` and `CompatibilityWriteRefused::fromQueryException()` read it back.

**Pinned by.** `InstallerTest::it_refuses_a_change_to_a_read_only_attribute_with_what_why_and_what_to_do()` reads the three parts. `EventBridgeOutsideTransactionTest::it_rolls_back_its_transaction_when_a_queued_bridged_write_fails_so_the_worker_records_the_failed_job()` asserts the literal CM001. `InstallerTest::it_fails_loudly_on_writes_a_view_with_triggers_cannot_take()` asserts that other failures are not refusals.

## INV-5: A concurrent change raises 40001

**Rule.** After its locks, the update trigger reads again every attribute the primary and retained rows hold. When that read differs from `OLD`, it raises SQLSTATE 40001, not CM001. Dependent attributes are left out.

**Why.** An INSTEAD OF trigger receives `OLD` from the statement's snapshot. A change committed after that snapshot would otherwise be overwritten. A serialization failure tells the caller to retry.

**Code that must agree.** `Compiler::conflict()`, called in `Compiler::updateFunction()` after `Compiler::loadPrevious()`. It reads each attribute with `Compiler::read()`, as the view does, and raises `SqlState::SerializationFailure`. `CompatibilityWriteRefused::isRefusal()` must not accept it.

**Pinned by.** No test pins the conflict check.

## INV-6: A change to a shared current table mirrors every previous model over it

**Rule.** When a current record changes, every map with a previous model over that record's table raises its previous model's events.

**Why.** Two previous models can read one current row. A write through either side must reach the listeners of both.

**Code that must agree.** `AffectedRecords::acrossMaps()`, `MirrorIndex::forTable()`, `MirrorIndex::add()` and `KeyTranslator::previousKeys()`.

**Pinned by.** `EventBridgeTest::it_raises_the_events_of_every_previous_model_over_one_current_record()`.

## INV-7: The update trigger writes only what changed

**Rule.** The update trigger writes a table only when one of its attributes changed, and a column only when its own attribute changed.

**Why.** An unchanged table keeps its rows as they are, and fires none of its triggers. A column whose attribute did not change keeps the value stored now.

**Code that must agree.** `SqlFragments::changed()`, `Compiler::updateTable()`, `Compiler::updateDependent()` and `Compiler::update()`.

**Pinned by.** `InstallerTest::it_writes_only_the_tables_whose_attributes_changed()`. `EventBridgeTest::it_raises_nothing_for_a_write_that_changes_no_attribute_of_the_other_side()` pins the result the bridge sees.

## INV-8: Each stored column has one owner

**Rule.** At most one attribute writes a given column, and no attribute writes the primary foreign key or a designation column. A fill from `PrimaryCurrentModel::fillWhenCreatedFromPrevious()` or `DependentCurrentModel::fillWhenCreatedFromPrevious()` never sets a column an attribute writes.

**Why.** The update trigger writes each column from one attribute's change. The map maintains the link and the designation itself.

**Code that must agree.** `ResolvedMap::validateAttributes()`, `Compiler::validateColumns()` and `Compiler::insertValues()`.

**Pinned by.** `ResolvedMapTest::it_refuses_two_attributes_that_own_one_stored_column()`, `ResolvedMapTest::it_refuses_an_attribute_the_tables_cannot_store()` and `DependentCurrentModelTest::it_refuses_an_attribute_that_writes_the_designation()`. No test pins the fill collision.

## INV-9: Each map owns a prefix no other map's prefix begins

**Rule.** Every trigger and function of a map starts with `compatibility__<name>__` and fits in 63 characters. A map name has at most 24 lowercase letters, digits and single underscores, and does not end in an underscore.

**Why.** The Installer finds a map's triggers and functions by prefix alone. A name with a double or trailing underscore could make one map's prefix begin another's. Uninstalling one map would then drop the other's objects.

**Code that must agree.** `ResolvedMap::of()` checks the name. `ResolvedMap::objectPrefix()`, `ResolvedMap::function()`, `ResolvedMap::refuseFunction()` and `ResolvedMap::designateFunction()` build every name, through the private `ResolvedMap::object()`. `Installer::dropObjects()` matches the prefix through `SqlFragments::like()`.

**Pinned by.** `ResolvedMapTest::it_refuses_a_name_that_cannot_prefix_database_objects()`, `InstallerTest::it_recompiles_a_compatibility_map_without_duplicating_its_triggers()` and `InstallerTest::it_uninstalls_the_compatibility_view_and_leaves_the_current_models_untouched()`. No test pins the 63-character limit.

## INV-10: Reads and writes carry their types

**Rule.** The view casts each mapped attribute to its declared type. The triggers cast each written value to its column's type in the catalog.

**Why.** A mapped attribute's type can differ from the column that stores it. The workbench `price` is text in the view and integer cents in `gadgets`.

**Code that must agree.** `ResolvedMap::validateAttributes()` requires `Attribute::asType()`. `Compiler::read()` casts reads, and `Compiler::value()` casts writes through `ColumnCatalog::type()`.

**Pinned by.** `InstallerTest::it_exposes_every_attribute_with_its_declared_previous_model_type()`, `InstallerTest::it_stores_a_renamed_and_retyped_attribute_once_in_its_owner()` and `ResolvedMapTest::it_refuses_a_mapped_attribute_without_a_type_when_the_map_is_registered()`.

## INV-11: A write through the view needs both rows

**Rule.** An update or delete through the view refuses with CM001 when the retained row is missing. An update that writes the primary row also refuses when it touches other than one row.

**Why.** A write with nowhere to store its change would otherwise succeed silently. The hint points at `compatibility:check`.

**Code that must agree.** `Compiler::loadPrevious()`, `Compiler::updateTable()` for the primary alias, and `Compiler::missing()`.

**Pinned by.** No test pins these refusals.

## INV-12: A designation pins the dependent row

**Rule.** When a dependent declares a designation, the previous model uses only the row it names. It reads, writes and mirrors that row only while the row is readable. A write to a dependent attribute refuses while the named row exists but is not readable.

**Why.** Without it, the previous model would switch to another row when the first stops matching its readable condition.

**Code that must agree.** `ResolvedMap::locate()` adds the designation to the condition. `Compiler::designateFunction()` fills an empty designation, and `Compiler::designate()` sets it for rows the view creates. `Compiler::updateDependent()` refuses, and `Compiler::validateColumns()` requires the column. `DriftQueries::brokenDesignations()` reports a broken one.

**Pinned by.** Every test in `DependentCurrentModelTest`, for example `DependentCurrentModelTest::a_previous_model_write_to_a_dependent_attribute_is_refused_while_the_designated_row_is_not_readable()`.

## INV-13: Hidden records are hidden from the drift check too

**Rule.** A record a hidden state covers is absent from the view, and the drift check does not report it.

**Why.** A hidden record has no current rows on purpose. Reporting it would make the check fail on every run.

**Code that must agree.** `Compiler::view()`, `DriftQueries::previousWithoutCurrent()` and `DriftQueries::brokenDesignations()` build the same `COALESCE((condition), false)` test.

**Pinned by.** `InstallerTest::it_hides_the_records_a_hidden_state_names()` and `CheckDriftTest::it_passes_when_every_record_has_its_previous_and_current_models()`.

## INV-14: A refused state checked on a table holds for every writer

**Rule.** A refused state that names `UnrepresentableState::checkedOn()` compiles to a trigger on that table, which runs for every insert and update, the view's included.

**Why.** The state is unrepresentable whoever writes it, and the applying flag does not exempt the view.

**Code that must agree.** `Compiler::refuseFunction()`, which never calls `Compiler::skipWhileApplying()`. `Compiler::triggers()` attaches it `BEFORE INSERT OR UPDATE`. `DriftQueries::refusedStates()` reports rows already in the state.

**Pinned by.** `InstallerTest::it_applies_a_refused_state_to_every_write_that_reaches_it()` and `CheckDriftTest::it_names_the_record_and_the_attribute_of_each_divergence_made_by_hand()`.

## INV-15: A mirrored listener may refuse a write, never change it

**Rule.** A mirrored `-ing` listener that returns false, or changes an attribute, refuses the whole write with `CompatibilityWriteRefused`.

**Why.** The write has already reached the database when mirrored events fire, so a listener's change has nowhere to go.

**Code that must agree.** `EventReplayer::ing()`, `EventReplayer::changed()` and `EventReplayer::equivalent()`. `CompatibilityWriteRefused::byMirroredListener()` and `CompatibilityWriteRefused::byMirroredChange()` build the refusals.

**Pinned by.** `EventBridgeTest::it_rolls_back_the_whole_write_when_a_mirrored_listener_refuses()` and `EventBridgeTest::it_refuses_a_mirrored_listener_that_changes_the_mirrored_record()`.

## INV-16: A replayed instance never begins a pending write

**Rule.** The bridge ignores the arming events of an instance it is replaying. A native write inside a mirrored listener begins its own pending write, on top of the connection's stack.

**Why.** A replayed instance's events describe a write that already happened. Beginning a pending write for it would lock and read records for a statement that never comes.

**Code that must agree.** `EventReplayer::isReplaying()` and `EventReplayer::record()`, `EventBridge::begin()` and `EventBridge::top()`.

**Pinned by.** `EventBridgeTest::it_mirrors_a_write_a_mirrored_listener_makes_as_a_change_of_its_own()` and `EventBridgeTest::it_runs_an_excluded_listener_for_a_native_write_inside_a_mirrored_event()`.

## INV-17: A delete completes on an UPDATE

**Rule.** A pending delete accepts an `UPDATE` of the model's table as its statement.

**Why.** Eloquent sends a soft delete as an `UPDATE` of the deleted-at column.

**Code that must agree.** `ModelEvent::operation()`, `Operation::accepts()`, `ParsedWrite::of()` and `PendingMirroredWrite::expects()`.

**Pinned by.** `EventBridgeTest::it_raises_the_soft_delete_and_restore_events_through_the_primary_current_model()`.

## INV-18: An excluded listener skips mirrored dispatches only

**Rule.** An excluded listener is skipped only while the bridge dispatches for the instance it replays. It runs for every native event, including one raised inside a mirrored listener.

**Why.** An event mirror excludes a listener from writes through the other side, not from the model's own writes.

**Code that must agree.** `EventReplayer::dispatch()`, `EventReplayer::mask()`, `EventReplayer::guard()` and `EventReplayer::unmask()`. The `Exclusion` is held per subject. `ExcludedListener::matches()` matches the listener.

**Pinned by.** `EventBridgeTest::it_skips_an_excluded_listener_for_mirrored_events_only()`, `EventBridgeTest::it_skips_an_excluded_listener_and_records_each_mirrored_event_once_through_a_decorating_dispatcher()`, `EventBridgeTest::it_skips_an_excluded_listener_for_a_mirrored_create_through_a_decorating_dispatcher_and_runs_it_for_a_native_create()` and `EventBridgeTest::it_runs_an_excluded_listener_for_a_native_write_inside_a_mirrored_event()`.

## INV-19: A failed write at level 0 releases its transaction

**Rule.** A failed write that began at transaction level 0 is closed by the first of four events. They are the next statement, the next bridged write, a queue worker's next loop and application termination.

**Why.** The bridge opened that transaction, and nothing else would close it. PostgreSQL refuses every further statement in an aborted transaction.

**Code that must agree.** The `beforeExecuting` callback in `EventBridge::watch()`, `EventBridge::finishFailed()`, `EventBridge::releaseFailed()` and `EventBridge::begin()`. `Provider::boot()` listens for `Looping` and the terminating callbacks.

**Pinned by.** `EventBridgeOutsideTransactionTest::it_rolls_back_its_transaction_before_the_next_statement_after_a_database_refusal_outside_a_transaction()`, `EventBridgeOutsideTransactionTest::it_releases_the_transaction_left_by_a_database_refusal_on_the_next_bridged_write()`, `EventBridgeOutsideTransactionTest::it_rolls_back_its_transaction_when_a_queued_bridged_write_fails_so_the_worker_records_the_failed_job()` and `EventBridgeOutsideTransactionTest::it_releases_the_transaction_left_by_a_database_refusal_when_the_application_terminates()`.

## INV-20: Inside a caller's transaction, a failure behaves as a native one

**Rule.** Inside a caller's transaction, the bridge works in a savepoint Laravel does not count. A PHP failure rolls back only that savepoint. A failed statement leaves the caller's transaction aborted, as a native failed statement would.

**Why.** The caller owns its transaction and decides how to recover from a failed statement.

**Code that must agree.** `EventBridge::begin()` names the savepoint, and `EventBridge::close()` rolls it back or releases it. `EventBridge::complete()` runs the replay inside `Connection::transaction()`. `EventBridge::finishFailed()` drops a pending savepoint write without a rollback.

**Pinned by.** `EventBridgeOutsideTransactionTest::it_leaves_a_caller_transaction_aborted_after_a_database_refusal_inside_it_as_a_native_write_would()`, `EventBridgeTest::it_leaves_the_callers_transaction_usable_after_a_database_refusal()` and `EventBridgeTest::it_rolls_back_the_write_and_its_after_commit_work_when_a_mirrored_listener_throws()`.

## INV-21: A faked dispatcher cannot hide a mirrored write

**Rule.** A write to a mirrored relation throws `CompatibilityEventMirrorBypassed` when the model dispatcher is an `EventFake`. The fake recorded the arming event instead of running the bridge's listener, so no pending write began.

**Why.** A test that fakes every event and writes through a mirrored model proves nothing about the other side.

**Code that must agree.** `EventBridge::guard()`, `MirrorIndex::modelsOf()`, `MirrorIndex::mapsOf()` and `ModelEvent::arming()`. `EventBridge::withoutMirroring()` switches the guard off.

**Pinned by.** `EventBridgeTest::it_fails_loudly_when_a_test_fakes_the_model_events()`, `EventBridgeTest::it_mirrors_under_a_fake_that_leaves_model_events_alone()` and `EventBridgeTest::it_switches_the_mirror_off_only_when_a_test_says_so()`.

## INV-22: Compiled identifiers are full words

**Rule.** Every name the compiler adds is a full word: `previous_row`, `affected_rows`, `key_<alias>`, `created_key`, the object prefix and the function suffixes. An alias is a lowercase identifier other than `new`, `old` and the PostgreSQL reserved words.

**Why.** A reader of the compiled SQL should not decode abbreviations. A reserved word as an alias would fail the install with a syntax error.

**Code that must agree.** `ResolvedMap::of()` and `ReservedWords::POSTGRESQL`. `Compiler::variables()` names `previous_row`, `affected_rows` and `key_<alias>`, and `Compiler::primaryInsertFunction()` names `created_key`.

**Pinned by.** `InstallerTest::it_compiles_only_full_word_aliases_variables_and_object_names()` and `ResolvedMapTest::it_refuses_an_alias_that_is_a_reserved_postgresql_word()`. The first compiles the workbench maps, whose aliases are full words too. An application chooses its own aliases.
