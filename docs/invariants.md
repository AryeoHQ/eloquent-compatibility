# Invariants

Each rule below holds today, and a change must keep it. Each names the code that must agree and the tests that pin it. Where no test pins a rule, the entry says so: a change there needs a reviewer's eye, or a new test.

## INV-1: Locks follow one order

**Rule.** The event bridge and the triggers lock the primary row before the retained row. A refuse function on a dependent's table or the retained table locks the primary row before it takes the refused-state lock.

**Why.** The bridge locks before the statement, and the trigger locks the same rows again. Two writers that take locks in one order cannot deadlock on these rows.

**Exceptions.** Two lock paths do not follow the order:

- **A writer that locks a dependent row and then updates it.** It can deadlock a concurrent write through the view with 40P01. The view writer holds the primary row `FOR UPDATE` and waits on the dependent row. The refuse function's `FOR KEY SHARE` on the primary row waits on the view writer. A raw write and a write through the event bridge both deadlock on this pattern.
- **A newcomer record in the bridge's second pass.** `AffectedRecords::before()` calls `AffectedRecords::of()` again under its first locks, for every operation. A record found only on that second call is a newcomer, such as a dependent row another transaction committed before the first lock, or another map's previous record for it. The bridge locks the newcomers after every record of the first pass, sorted by `RecordRole::lockOrder()` among themselves. The role order does not hold across the two passes.

**Code that must agree.** `RecordRole::lockOrder()` puts primary records first, previous records second and dependent records last. The private `AffectedRecords::lock()` sorts by it, then by lock table and key. `KeyTranslator::previousRecord()` makes a previous record lock its retained table. `Compiler::updateFunction()` and `Compiler::deleteFunction()` call `Compiler::lockPrimary()` before `Compiler::loadPrevious()`. `Compiler::refuseFunction()` takes `FOR KEY SHARE` on the primary row before `pg_advisory_xact_lock()`.

**Pinned by.** `InstallerTest::it_does_not_deadlock_a_write_through_the_view_against_a_writer_holding_the_refused_state_lock()` pins the refused-state lock's place in the order, with a second writer in a child process. `AffectedRecordsTest::it_locks_the_primary_row_then_the_retained_row_then_the_dependent_rows_before_a_previous_model_write()`, `AffectedRecordsTest::it_locks_the_own_row_of_a_current_model_in_the_same_order_and_snapshots_only_the_records_it_mirrors()`, `AffectedRecordsTest::it_locks_the_records_of_one_role_by_table_and_then_by_key()` and `AffectedRecordsTest::it_locks_the_retained_rows_of_every_map_over_a_current_record_in_table_order()` pin the bridge's order. `RecordRoleTest::it_locks_the_primary_record_first_then_the_previous_record_then_the_dependent_records()` pins `RecordRole::lockOrder()`. `AffectedRecordsTest::it_locks_a_dependent_row_that_appears_under_the_first_locks_after_them_and_snapshots_it()` pins the newcomer exception. No test pins the dependent-row deadlock exception, or the order of the primary and retained locks inside the triggers.

The second pass has a limit. It finds every dependent row another transaction inserts, because an insert that has not committed by the first lock waits on the locked primary row. Its foreign key check takes `FOR KEY SHARE` on that row. An update can make an existing dependent row readable without changing its primary foreign key, so it takes no lock on the primary row. Only a refuse function on that dependent's table blocks such an update, because the refuse function takes `FOR KEY SHARE` on the primary row first. The workbench place has one, `second_root_place`. Without one, that update can commit after the second pass. The bridge then holds no row from before for that dependent row, and it replays the row's create events in place of its update events.

## INV-2: One expression locates a dependent row

**Rule.** The view, the update trigger and the event bridge pick a dependent row with the same subquery, from `ResolvedMap::locate()`.

**Why.** The bridge must mirror the row the view reads and the trigger writes. Two copies of the condition could pick two rows. A delete that removes the row is the one exception: the trigger removes every dependent row, and INV-25 mirrors them all.

**Code that must agree.** `ResolvedMap::locate()`, called by `Compiler::read()`, `Compiler::updateDependent()` and `KeyTranslator::currentKeys()`.

**Pinned by.** `DependentCurrentModelTest::a_previous_model_write_mirrors_only_the_designated_dependent_row()` and `DependentCurrentModelTest::the_previous_model_reads_no_dependent_row_once_its_designated_row_is_no_longer_readable_and_reads_no_other()`. `KeyTranslatorTest::it_translates_a_previous_key_into_the_key_of_each_current_record_the_view_reads()` pins the row the bridge picks over a child row and a soft-deleted row.

## INV-3: The view's own writes skip this map's current-side triggers

**Rule.** The insert, update and delete triggers set the applying flag to the map's name around their writes. The primary insert, primary delete and designate triggers return early while it holds that name.

**Why.** An insert through the view writes the primary row itself. Without the flag, the primary insert trigger would add a second retained row. The flag holds the map's name, so another map over the same primary table still reacts.

**Code that must agree.** `Setting::Applying` and `Setting::for()`. `Compiler::applying()` in `Compiler::insertFunction()`, `Compiler::updateFunction()` and `Compiler::deleteFunction()`. `Compiler::skipWhileApplying()` in `Compiler::primaryInsertFunction()`, `Compiler::primaryDeleteFunction()` and `Compiler::designateFunction()`. The flag is transaction-local, so it cannot outlive the transaction.

**Pinned by.** `InstallerTest::it_inserts_one_record_into_every_table_and_returns_the_previous_model_key()` asserts one retained row. `InstallerTest::it_keeps_two_previous_models_over_one_current_record_in_step()` asserts that the other map reacts.

## INV-4: Every database refusal is CM001

**Rule.** Every refusal the compiled SQL raises uses SQLSTATE CM001, with a message, a detail and a hint.

**Why.** PHP recognises a refusal by that one code. It splits the three parts into what was refused, why, and what to do instead.

**Code that must agree.** `SqlState::Refused`, `SqlFragments::refuse()` and `SqlFragments::raise()`. Every refusal in `Compiler` calls `SqlFragments::refuse()`: in `Compiler::updateFunction()`, `Compiler::notNullChecks()`, `Compiler::updateDependent()`, `Compiler::missing()` and `Compiler::refuseFunction()`. `CompatibilityWriteRefused::isRefusal()` and `CompatibilityWriteRefused::fromQueryException()` read it back.

**Pinned by.** `InstallerTest::it_refuses_a_change_to_a_read_only_attribute_with_what_why_and_what_to_do()` reads the three parts. `EventBridgeOutsideTransactionTest::it_rolls_back_its_transaction_when_a_queued_bridged_write_fails_so_the_worker_records_the_failed_job()` asserts the literal CM001. `InstallerTest::it_fails_loudly_on_writes_a_view_with_triggers_cannot_take()` asserts that other failures are not refusals. `SqlFragmentsTest::it_refuses_with_a_message_expression_and_a_quoted_detail_and_hint_that_php_reads_back()`, `SqlFragmentsTest::it_raises_the_given_sqlstate_with_its_message_detail_and_hint()` and `SqlStateTest::it_names_the_refusal_code_and_the_serialization_failure_code()` pin the SQL side. `CompatibilityWriteRefusedTest::it_counts_itself_and_a_query_refused_by_the_database_as_a_refusal_and_nothing_else()` and `CompatibilityWriteRefusedTest::it_splits_a_refusal_the_database_raised_into_its_message_detail_and_hint()` pin the PHP side.

## INV-5: A concurrent change raises 40001

**Rule.** After its locks, the update trigger reads again every attribute the primary and retained rows hold. When that read differs from `OLD`, it raises SQLSTATE 40001, not CM001. Dependent attributes are left out.

**Why.** An INSTEAD OF trigger receives `OLD` from the statement's snapshot. A change committed after that snapshot would otherwise be overwritten. A serialization failure tells the caller to retry.

**Code that must agree.** `Compiler::conflict()`, called in `Compiler::updateFunction()` after `Compiler::loadPrevious()`. It reads each attribute with `Compiler::read()`, as the view does, and raises `SqlState::SerializationFailure`. `CompatibilityWriteRefused::isRefusal()` must not accept it.

**Pinned by.** `CompilerTest::it_raises_a_serialization_failure_when_a_primary_or_retained_attribute_changed_after_the_statement_read_the_view()`, with a primary and a retained change. `CompilerTest::it_lets_a_write_through_proceed_when_only_a_dependent_attribute_changed_after_the_statement_read_the_view()` pins that dependent attributes are left out.

## INV-6: A change to a shared current table mirrors every previous model over it

**Rule.** When a current record changes, every map with a previous model over that record's table raises its previous model's events.

**Why.** Two previous models can read one current row. A write through either side must reach the listeners of both.

**Code that must agree.** `AffectedRecords::acrossMaps()`, `MirrorIndex::forRelation()`, `MirrorIndex::add()` and `KeyTranslator::previousKeys()`.

**Pinned by.** `EventBridgeTest::it_raises_the_events_of_every_previous_model_over_one_current_record()` and `AffectedRecordsTest::it_finds_the_previous_records_of_every_other_map_over_a_current_record()`. `MirrorIndexTest::it_lists_the_maps_and_aliases_of_each_current_table_and_of_no_view_or_retained_table()` pins `MirrorIndex::forRelation()`, and `KeyTranslatorTest::it_lists_every_previous_key_over_a_primary_key_in_key_order()` pins `KeyTranslator::previousKeys()`.

## INV-7: The update trigger writes only what changed

**Rule.** The update trigger writes a table only when one of its attributes changed, and a column only when its own attribute changed.

**Why.** An unchanged table keeps its rows as they are, and fires none of its triggers. A column whose attribute did not change keeps the value stored now.

**Code that must agree.** `SqlFragments::changed()`, `Compiler::updateTable()`, `Compiler::updateDependent()` and `Compiler::update()`.

**Pinned by.** `InstallerTest::it_writes_only_the_tables_whose_attributes_changed()`. `EventBridgeTest::it_raises_nothing_for_a_write_that_changes_no_attribute_of_the_other_side()` pins the result the bridge sees. `SqlFragmentsTest::it_tells_whether_any_listed_column_changed_counting_null_as_a_value()` pins `SqlFragments::changed()`.

## INV-8: Each stored column has one owner

**Rule.** At most one attribute writes a given column, and no attribute writes the primary foreign key or a designation column. A fill from `PrimaryCurrentModel::fillWhenCreatedFromPrevious()` or `DependentCurrentModel::fillWhenCreatedFromPrevious()` never sets a column an attribute writes.

**Why.** The update trigger writes each column from one attribute's change. The map maintains the link and the designation itself.

**Code that must agree.** `ResolvedMap::validateAttributes()`, `Compiler::validateColumns()` and `Compiler::insertValues()`.

**Pinned by.** `ResolvedMapTest::it_refuses_two_attributes_that_own_one_stored_column()`, `ResolvedMapTest::it_refuses_an_attribute_the_tables_cannot_store()` and `DependentCurrentModelTest::it_refuses_an_attribute_that_writes_the_designation()`. `CompilerTest::it_refuses_at_compile_time_a_map_the_tables_cannot_store()` pins the fill collision, in its `primary_fill_collision` and `dependent_fill_collision` cases.

## INV-9: Each map owns a prefix no other map's prefix begins

**Rule.** Every trigger and function of a map starts with `compatibility__<name>__` and fits in 63 characters. A map name has at most 24 lowercase letters, digits and single underscores, and does not end in an underscore. The Installer drops only the prefixed triggers on the current schema's tables, the prefixed functions of that schema and the view in that schema.

**Why.** The Installer finds a map's triggers and functions by prefix and schema alone. A name with a double or trailing underscore could make one map's prefix begin another's. Uninstalling one map would then drop the other's objects. Without the schema, an install in one schema would drop the same map's triggers in another, or a trigger on another schema's table.

**Code that must agree.** `ResolvedMap::of()` checks the name. `ResolvedMap::objectPrefix()`, `ResolvedMap::functionName()`, `ResolvedMap::refuseFunctionName()` and `ResolvedMap::designateFunctionName()` build every name, through the private `ResolvedMap::object()`. `Installer::dropObjects()` matches the prefix through `SqlFragments::like()`. It drops a trigger only when its table and its function are both in `current_schema()`, and a function only when it is in that schema. It names the schema through `to_regnamespace(quote_ident(current_schema()))`, so a schema whose name needs quoting resolves. The private `Installer::relation()` looks the view up in that schema alone, and `Installer::install()` and `Installer::uninstall()` act on the view only when it finds one. `Compiler` creates every function in that same schema.

**Pinned by.** `ResolvedMapTest::it_refuses_a_name_that_cannot_prefix_database_objects()`, `InstallerTest::it_recompiles_a_compatibility_map_without_duplicating_its_triggers()` and `InstallerTest::it_uninstalls_the_compatibility_view_and_leaves_the_current_models_untouched()`. `InstallerTest::it_leaves_the_same_compatibility_map_in_another_schema_installed()` and `InstallerTest::it_keeps_a_prefixed_trigger_on_another_schemas_table_that_calls_a_function_of_this_schema()` pin the schema. `InstallerTest::it_installs_recompiles_and_uninstalls_in_a_schema_whose_name_needs_quoting()` pins a quoted schema name. `InstallerTest::it_installs_recompiles_and_uninstalls_ahead_of_a_later_schema_that_has_the_compatibility_map()` and `InstallerTest::it_uninstalls_nothing_of_a_later_schema_when_the_current_schema_has_no_compatibility_view()` pin the view's schema. `SqlFragmentsTest::it_matches_only_values_that_begin_with_the_prefix_taken_literally()` pins `SqlFragments::like()`. `CompilerTest::it_refuses_at_compile_time_a_map_the_tables_cannot_store()` pins the 63-character limit in its `long_refuse_function` and `long_designate_function` cases. `CompilerTest::it_installs_an_object_name_of_exactly_63_characters_without_truncating_it()` and `CompilerTest::it_compiles_every_trigger_function_of_a_map_whose_name_has_the_most_characters_allowed()` pin the limit's edge.

## INV-10: Reads and writes carry their types

**Rule.** The view casts each mapped attribute to its declared type. The triggers cast each written value to its column's type in the catalog.

**Why.** A mapped attribute's type can differ from the column that stores it. The workbench `price` is text in the view and integer cents in `gadgets`.

**Code that must agree.** `ResolvedMap::validateAttributes()` requires `Attribute::asType()`. `Compiler::read()` casts reads, and `Compiler::value()` casts writes through `ColumnCatalog::type()`.

**Pinned by.** `InstallerTest::it_exposes_every_attribute_with_its_declared_previous_model_type()`, `InstallerTest::it_stores_a_renamed_and_retyped_attribute_once_in_its_owner()` and `ResolvedMapTest::it_refuses_a_mapped_attribute_without_a_type_when_the_map_is_registered()`. `ColumnCatalogTest::it_gives_the_type_of_a_column_or_none_for_a_column_the_table_does_not_have()` pins `ColumnCatalog::type()`.

## INV-11: A write through the view needs both rows

**Rule.** An update or delete through the view refuses with CM001 when the retained row is missing. An update that writes the primary row also refuses when it touches other than one row.

**Why.** A write with nowhere to store its change would otherwise succeed silently. The hint points at `compatibility:check`.

**Code that must agree.** `Compiler::loadPrevious()`, `Compiler::updateTable()` for the primary alias, and `Compiler::missing()`.

**Pinned by.** `CompilerTest::it_refuses_a_write_through_the_view_whose_retained_row_is_gone()`, with an update and a delete, and with the hint that names `compatibility:check`. `CompilerTest::it_refuses_a_write_through_the_view_that_updates_no_primary_row()`.

## INV-12: A designation pins the dependent row

**Rule.** When a dependent declares a designation, the previous model uses only the row it names. It reads, writes and mirrors that row only while the row is readable. A write to a dependent attribute refuses while the named row exists but is not readable.

**Why.** Without it, the previous model would switch to another row when the first stops matching its readable condition.

**Code that must agree.** `ResolvedMap::locate()` adds the designation to the condition. `Compiler::designateFunction()` fills an empty designation, and `Compiler::designate()` sets it for rows the view creates. `Compiler::updateDependent()` refuses, and `Compiler::validateColumns()` requires the column. `DriftQueries::brokenDesignations()` reports a broken one.

**Pinned by.** Every test in `DependentCurrentModelTest`, for example `DependentCurrentModelTest::a_previous_model_write_to_a_dependent_attribute_is_refused_while_the_designated_row_is_not_readable()`.

## INV-13: Hidden records are hidden from the drift check too

**Rule.** A record a hidden state covers is absent from the view, and the drift check does not report it.

**Why.** A hidden record has no current rows on purpose. Reporting it would make the check fail on every run.

**Code that must agree.** `Compiler::view()`, `DriftQueries::previousWithoutCurrent()` and `DriftQueries::brokenDesignations()` build the same `COALESCE((condition), false)` test.

**Pinned by.** `InstallerTest::it_hides_the_records_a_hidden_state_names()`, `CheckDriftTest::it_passes_when_every_record_has_its_previous_and_current_models()` and `DriftQueriesTest::it_leaves_out_a_retained_row_a_hidden_unrepresentable_state_covers_and_no_other()`.

## INV-14: A refused state checked on a table holds for every writer

**Rule.** A refused state that names `UnrepresentableState::checkedOn()` compiles to a trigger on that table, which runs for every insert and update, the view's included.

**Why.** The state is unrepresentable whoever writes it, and the applying flag does not exempt the view.

**Code that must agree.** `Compiler::refuseFunction()`, which never calls `Compiler::skipWhileApplying()`. `Compiler::triggers()` attaches it `BEFORE INSERT OR UPDATE`. `DriftQueries::refusedStates()` reports rows already in the state.

**Pinned by.** `InstallerTest::it_applies_a_refused_state_to_every_write_that_reaches_it()` and `CheckDriftTest::it_names_the_record_and_the_attribute_of_each_divergence_made_by_hand()`. `CompilerTest::it_attaches_each_trigger_function_to_its_table_and_events()` pins the `BEFORE INSERT OR UPDATE` trigger, and `DriftQueriesTest::it_finds_each_record_in_a_refused_state()` pins the drift query.

The trigger checks each writer against committed rows and its own. On the primary table, two concurrent writers can both reach a cross-row state, as INV-23 records.

## INV-15: A mirrored listener may refuse a write, never change it

**Rule.** A mirrored `-ing` listener that returns false, or changes an attribute, refuses the whole write with `CompatibilityWriteRefused`.

**Why.** The write has already reached the database when mirrored events fire, so a listener's change has nowhere to go.

**Code that must agree.** `EventReplayer::ing()`, `EventReplayer::changed()` and `EventReplayer::equivalent()`. `EventReplayer::number()` compares two numbers by their exact decimal value, never through a float, so a change below float precision still counts. `CompatibilityWriteRefused::byMirroredListener()` and `CompatibilityWriteRefused::byMirroredChange()` build the refusals.

**Pinned by.** `EventBridgeTest::it_rolls_back_the_whole_write_when_a_mirrored_listener_refuses()` and `EventBridgeTest::it_refuses_a_mirrored_listener_that_changes_the_mirrored_record()`. `EventBridgeTest::it_refuses_a_mirrored_listener_change_to_a_number_that_a_float_cannot_tell_apart()` and `EventBridgeTest::it_accepts_a_mirrored_listener_that_rewrites_a_number_as_the_same_value()` pin the number comparison. `EventReplayerTest::it_refuses_the_write_when_a_mirrored_listener_of_an_ing_event_returns_false()`, `EventReplayerTest::it_lets_a_mirrored_listener_of_an_ed_event_return_false()`, `EventReplayerTest::it_refuses_the_write_when_a_mirrored_listener_changes_the_record()`, `EventReplayerTest::it_refuses_a_listener_change_to_a_number_that_a_float_cannot_tell_apart()` and `EventReplayerTest::it_accepts_a_listener_rewrite_to_the_same_number_or_the_same_json()` pin the same rule on `EventReplayer::replay()` alone.

## INV-16: A replayed instance never begins a pending write

**Rule.** The bridge ignores the beginning events of an instance it is replaying. A native write inside a mirrored listener begins its own pending write, on top of the connection's stack.

**Why.** A replayed instance's events describe a write that already happened. Beginning a pending write for it would lock and read records for a statement that never comes.

**Code that must agree.** `EventReplayer::isReplaying()` and `EventReplayer::replay()`, `EventBridge::begin()` and `EventBridge::top()`.

**Pinned by.** `EventBridgeTest::it_mirrors_a_write_a_mirrored_listener_makes_as_a_change_of_its_own()` and `EventBridgeTest::it_runs_an_excluded_listener_for_a_native_write_inside_a_mirrored_event()`. `EventReplayerTest::it_reports_only_the_instance_it_replays_as_replaying_and_only_while_it_replays()` and `EventReplayerTest::it_stops_reporting_an_instance_as_replaying_once_a_listener_refuses_it()` pin `EventReplayer::isReplaying()`.

## INV-17: A delete completes on an UPDATE

**Rule.** A pending delete accepts an `UPDATE` of the model's table as its statement.

**Why.** Eloquent sends a soft delete as an `UPDATE` of the deleted-at column.

**Code that must agree.** `ModelEvent::operation()`, `Operation::accepts()`, `ParsedWrite::of()` and `PendingMirroredWrite::expects()`.

**Pinned by.** `EventBridgeTest::it_raises_the_soft_delete_and_restore_events_through_the_primary_current_model()`. `OperationTest::it_accepts_its_own_operation_and_a_delete_accepts_the_update_of_a_soft_delete()`, `ParsedWriteTest::it_parses_the_insert_update_soft_delete_and_force_delete_eloquent_emits()`, `PendingMirroredWriteTest::it_expects_the_operation_of_its_model_event_and_a_soft_delete_for_a_delete()` and `ModelEventTest::it_maps_each_beginning_event_to_its_operation_and_every_other_case_to_none()` pin each step.

## INV-18: An excluded listener skips mirrored dispatches only

**Rule.** An excluded listener is skipped only while the bridge dispatches for the instance it replays. It runs for every native event, including one raised inside a mirrored listener.

**Why.** An event mirror excludes a listener from writes through the other side, not from the model's own writes.

**Code that must agree.** `EventReplayer::dispatch()`, `EventReplayer::mask()`, `EventReplayer::guard()` and `EventReplayer::unmask()`. The `Exclusion` is held per subject. `ExcludedListener::matches()` matches the listener.

**Pinned by.** `EventBridgeTest::it_skips_an_excluded_listener_for_mirrored_events_only()`, `EventBridgeTest::it_skips_an_excluded_listener_and_records_each_mirrored_event_once_through_a_decorating_dispatcher()`, `EventBridgeTest::it_skips_an_excluded_listener_for_a_mirrored_create_through_a_decorating_dispatcher_and_runs_it_for_a_native_create()` and `EventBridgeTest::it_runs_an_excluded_listener_for_a_native_write_inside_a_mirrored_event()`. `EventReplayerTest::it_skips_an_excluded_listener_for_its_replayed_event_only()` and `EventReplayerTest::it_skips_an_excluded_listener_under_a_fake_that_leaves_model_events_alone()` pin the masking, and `ExcludedListenerTest` pins `ExcludedListener::matches()`.

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

**Rule.** A write to a mirrored relation throws `CompatibilityEventMirrorBypassed` when the model dispatcher is an `EventFake`. The fake recorded the beginning event instead of running the bridge's listener, so no pending write began. The guard keeps its count per fake and per relation, so each relation's bypass throws.

**Why.** A test that fakes every event and writes through a mirrored model proves nothing about the other side.

**Code that must agree.** `EventBridge::guard()`, `EventBridge::$faked`, `MirrorIndex::modelsOf()`, `MirrorIndex::mapsOf()` and `ModelEvent::beginning()`. Inside `EventBridge::withoutMirroring()`, the guard still stores each count and only skips the throw. So a faked write with the mirror off does not make the next raw write throw.

**Pinned by.** `EventBridgeTest::it_fails_loudly_when_a_test_fakes_the_model_events()`, `EventBridgeTest::it_fails_loudly_for_each_relation_a_faked_dispatcher_bypasses()`, `EventBridgeTest::it_mirrors_under_a_fake_that_leaves_model_events_alone()`, `EventBridgeTest::it_switches_the_mirror_off_only_when_a_test_says_so()` and `EventBridgeTest::it_lets_a_raw_write_through_after_a_faked_write_with_the_mirror_off()`. `MirrorIndexTest::it_lists_the_model_classes_over_a_relation_once()` and `MirrorIndexTest::it_lists_the_maps_over_a_relation_once()` pin the lookups the guard counts by.

## INV-22: Compiled identifiers are full words

**Rule.** Every name the compiler adds is a full word: `previous_row`, `affected_rows`, `key_<alias>`, `created_key`, the object prefix and the function suffixes. An alias is a lowercase identifier other than `new`, `old` and the PostgreSQL reserved words.

**Why.** A reader of the compiled SQL should not decode abbreviations. A reserved word as an alias would fail the install with a syntax error.

**Code that must agree.** `ResolvedMap::of()` and `ReservedWords::POSTGRESQL`. `Compiler::variables()` names `previous_row`, `affected_rows` and `key_<alias>`, and `Compiler::primaryInsertFunction()` names `created_key`.

**Pinned by.** `InstallerTest::it_compiles_only_full_word_aliases_variables_and_object_names()` and `ResolvedMapTest::it_refuses_an_alias_that_is_a_reserved_postgresql_word()`. `ReservedWordsTest::it_lists_every_word_the_server_reserves_as_an_alias()` checks `ReservedWords::POSTGRESQL` against the server. The first compiles the workbench maps, whose aliases are full words too. An application chooses its own aliases.

## INV-23: A refused-state check is serialized per record

**Rule.** A refuse function on a dependent's table or the retained table first takes `FOR KEY SHARE` on the primary row its row links, then `pg_advisory_xact_lock()`. The advisory lock's key is the refuse function's name, a colon and the row's primary foreign key, hashed with `hashtextextended()`. A refuse function on the primary table takes no lock.

**Why.** A condition that reads other rows of the same record would otherwise miss a concurrent writer's uncommitted row, and both writers would commit the state. The key must name both parts. Without the function name, two states would wait on each other. Without the primary foreign key, every writer of the table would wait on one lock. The primary row lock comes first so that the refused-state lock joins INV-1's order. INV-1 records the deadlock path that remains. A primary row has no primary foreign key, so the primary table stays unserialized. [0016](decisions/0016-a-refused-state-on-the-primary-table-is-not-serialized.md) records that limit.

**Code that must agree.** `Compiler::refuseFunction()` builds the lock from `ResolvedMap::refuseFunctionName()`, `ResolvedMap::dependent()` and `ResolvedMap::primaryForeignKey()`, or from the previous model's primary foreign key. The two locks are the function's first statements, before the `IF EXISTS` check. `Compiler::function()` declares no volatility, so the check after the wait reads a new snapshot under READ COMMITTED.

**Pinned by.** `InstallerTest::it_makes_a_concurrent_write_wait_for_the_refused_state_check_of_the_same_record()`, which writes from a second connection. `InstallerTest::it_does_not_deadlock_a_write_through_the_view_against_a_writer_holding_the_refused_state_lock()` pins the primary row lock. No test pins the primary-table limit or the REPEATABLE READ limit.

## INV-24: Each Installer method is atomic

**Rule.** `Installer::install()`, `Installer::recompile()` and `Installer::uninstall()` each run every statement inside one `Connection::transaction()`. Inside a caller's transaction, that is a savepoint.

**Why.** Each method drops the map's triggers before it creates or drops anything else. A failure part way would leave a half-installed map, or a map without its triggers. A new primary row would then get no retained row. A concurrent writer waits on the dropped triggers' locks until the commit.

**Code that must agree.** `Installer::install()` wraps `Installer::apply()` and `Installer::copyPrivileges()`. `Installer::recompile()` wraps `Installer::apply()`. `Installer::uninstall()` wraps `Installer::dropObjects()` and the `DROP VIEW`. The check for an existing view in `Installer::install()` stays outside, because it throws before any change.

**Pinned by.** `InstallerTest::it_leaves_nothing_behind_when_an_install_fails()`, `InstallerTest::it_keeps_the_installed_compatibility_map_when_a_recompile_fails()` and `InstallerTest::it_keeps_the_installed_compatibility_map_when_an_uninstall_fails()`. `InstallerTest` runs outside a test transaction, so these tests see a real commit and rollback.

## INV-25: A delete through the previous model mirrors every row it removes

**Rule.** A delete through the previous model that removes the row mirrors every dependent row under the record's primary foreign key. That includes a row another transaction commits before the bridge locks the primary row. A soft delete mirrors only the rows the view reads.

**Why.** The delete trigger removes every dependent row of the primary row, including rows the view does not read. Each removed row must raise its own delete events.

**Code that must agree.** `Compiler::deleteFunction()` deletes by primary foreign key. `KeyTranslator::dependentKeys()` selects by the same key. The private `AffectedRecords::removed()` turns those keys into records when the private `AffectedRecords::removes()` holds. `AffectedRecords::of()` calls it to find the records. `AffectedRecords::before()` calls `AffectedRecords::of()` a second time under the locks, so it finds newcomers too. A change to which rows the trigger deletes must change `KeyTranslator::dependentKeys()` with it.

**Pinned by.** `EventBridgeTest::it_raises_the_force_delete_events_of_every_dependent_row_a_previous_model_force_delete_removes()`. `EventBridgeOutsideTransactionTest::it_raises_the_force_delete_events_of_a_dependent_row_committed_before_the_primary_record_is_locked()` pins the newcomer, which a second connection commits. `AffectedRecordsTest::it_finds_every_dependent_row_a_previous_model_delete_removes()`, `AffectedRecordsTest::it_keeps_a_soft_delete_to_the_dependent_rows_the_previous_model_reads()` and `KeyTranslatorTest::it_lists_every_dependent_row_under_the_primary_row_of_a_previous_key()` pin the rows the bridge finds. `AffectedRecordsTest::it_locks_a_dependent_row_that_appears_under_the_first_locks_after_them_and_snapshots_it()` pins the newcomer's lock and snapshot.
