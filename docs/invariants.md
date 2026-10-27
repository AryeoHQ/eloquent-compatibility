# Invariants

Each rule below holds today, and a change must keep it. Each names the code that must agree and the tests that pin it. Where no test pins a rule, the entry says so: a change there needs a reviewer's eye, or a new test.

## INV-1: Locks follow one order

**Rule.** The event bridge and the triggers lock the primary row before the retained row. For a dependent model's write, the bridge locks the primary row of each primary foreign key the write names. The bridge and the update trigger lock the dependent rows last. A refuse function on a dependent's table or the retained table locks the primary row before it takes the refused-state lock.

**Why.** The bridge locks before the statement, and the trigger locks the same rows again. Two writers that take locks in one order cannot deadlock on these rows.

**Exceptions.** Two lock paths do not follow the order:

- **A writer that locks a dependent row before the primary row.** It can deadlock a concurrent update through the view with 40P01. The view writer holds the primary row `FOR UPDATE` and waits on the dependent row, which `Compiler::conflict()` locks on every update. A raw update locks the dependent row first, and its refuse function's `FOR KEY SHARE` on the primary row then waits on the view writer. A write through the event bridge locks the primary row before its statement, so it waits instead. It still deadlocks when the application locked the dependent row itself before the write.
- **A newcomer record in the bridge's second pass.** `AffectedRecords::before()` calls `AffectedRecords::of()` again under its first locks, for every operation. A record found only on that second call is a newcomer, such as a dependent row another transaction committed before the first lock, or another map's previous record for it. The bridge locks the newcomers after every record of the first pass, sorted by `RecordRole::lockOrder()` among themselves. The role order does not hold across the two passes.

**Code that must agree.** `RecordRole::lockOrder()` puts primary records first, previous records second and dependent records last. The private `AffectedRecords::lock()` sorts by it, then by lock table and key. `AffectedRecords::before()` adds a dependent write's primary records from the private `AffectedRecords::lockedOnly()`, so it locks them and snapshots none. `KeyTranslator::previousRecord()` makes a previous record lock its retained table. `Compiler::updateFunction()` and `Compiler::deleteFunction()` call `Compiler::lockPrimary()` before `Compiler::loadPrevious()`. `Compiler::conflict()` then locks each dependent row the record reads. `Compiler::refuseFunction()` takes `FOR KEY SHARE` on the primary row before `pg_advisory_xact_lock()`.

**Pinned by.** `InstallerTest::it_does_not_deadlock_a_write_through_the_view_against_a_writer_holding_the_refused_state_lock()` pins the refused-state lock's place in the order, with a second writer in a child process. `AffectedRecordsTest::it_locks_the_primary_row_then_the_retained_row_then_the_dependent_rows_before_a_previous_model_write()`, `AffectedRecordsTest::it_locks_the_own_row_of_a_current_model_in_the_same_order_and_snapshots_only_the_records_it_mirrors()`, `AffectedRecordsTest::it_locks_the_records_of_one_role_by_table_and_then_by_key()` and `AffectedRecordsTest::it_locks_the_retained_rows_of_every_map_over_a_current_record_in_table_order()` pin the bridge's order. `AffectedRecordsTest::it_locks_every_primary_row_a_dependent_write_names_before_its_retained_rows_and_snapshots_none_of_them()` pins a dependent's insert and its move between primary rows. `RecordRoleTest::it_locks_the_primary_record_first_then_the_previous_record_then_the_dependent_records()` pins `RecordRole::lockOrder()`. `AffectedRecordsTest::it_locks_a_dependent_row_that_appears_under_the_first_locks_after_them_and_snapshots_it()` pins the newcomer exception for a removing delete, and `EventBridgeOutsideTransactionTest::it_raises_the_update_events_of_a_readable_dependent_row_committed_before_the_primary_record_is_locked()` pins it for an update. No test pins the dependent-row deadlock exception, the bridged write that waits instead, or the order of the primary and retained locks inside the triggers.

The second pass has limits. It finds every dependent row another transaction inserts, because an insert that has not committed by the first lock waits on the locked primary row. Its foreign key check takes `FOR KEY SHARE` on that row. This holds only when each dependent's primary foreign key has a foreign key constraint to the primary table, or the dependent's table has a refuse function. The package does not require either. Without one, a row another transaction inserts after the second pass can escape it: a removing delete then removes that row without its delete events, and an update replays the row's create events in place of its update events. An update can make an existing dependent row readable without changing its primary foreign key, so it takes no lock on the primary row. Only a refuse function on that dependent's table blocks such an update, because the refuse function takes `FOR KEY SHARE` on the primary row first. The workbench place has one, `second_root_place`. Without one, that update can commit after the second pass. The bridge then holds no row from before for that dependent row, and it replays the row's create events in place of its update events.

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

**Rule.** After its locks, the update trigger locks each dependent row the record reads, then reads every attribute again. When that read differs from `OLD`, it raises SQLSTATE 40001, not CM001. A dependent row it no longer finds is not a conflict: its attributes compare as `OLD`. The trigger then writes only the dependent row it locked. When it locked none, but the view's subquery finds a row now, it raises 40001 too.

**Why.** An INSTEAD OF trigger receives `OLD` from the statement's snapshot. A change committed after that snapshot would otherwise be overwritten. A serialization failure tells the caller to retry.

**Code that must agree.** `Compiler::conflict()`, called in `Compiler::updateFunction()` after `Compiler::loadPrevious()`. It locks each dependent row through `ResolvedMap::locate()` into its `key_<alias>`, and joins that row by its key. It reads each other attribute with `Compiler::read()`, as the view does. It compares each attribute as [INV-36](#inv-36-an-attribute-without-a-btree-equality-compares-as-text) requires, and raises `SqlState::SerializationFailure`. `Compiler::updateDependent()` writes the row in `key_<alias>`, and selects no row of its own. Both raise through the private `Compiler::concurrentChange()`. `CompatibilityWriteRefused::isRefusal()` must not accept it.

**Pinned by.** `CompilerTest::it_raises_a_serialization_failure_when_a_primary_or_retained_attribute_changed_after_the_statement_read_the_view()`, with a primary and a retained change. `CompilerTest::it_raises_a_serialization_failure_when_a_dependent_attribute_changed_after_the_statement_read_the_view()` pins a changed place and a changed site, whether or not the stale write touches the dependent attribute. `CompilerTest::it_lets_a_write_through_proceed_when_its_dependent_row_is_gone_after_the_statement_read_the_view()` pins that a missing dependent row is not a conflict. `CompilerTest::it_raises_a_serialization_failure_when_the_dependent_row_it_waited_on_stops_being_readable_and_another_becomes_readable()` pins the row found only after the lock, with a second connection.

## INV-6: A change to a shared current table mirrors every previous model over it

**Rule.** When a current record changes, every map with a previous model over that record's table raises its previous model's events.

**Why.** Two previous models can read one current row. A write through either side must reach the listeners of both.

**Code that must agree.** `AffectedRecords::acrossMaps()`, `MirrorIndex::forRelation()`, `MirrorIndex::add()` and `KeyTranslator::previousKeys()`.

**Pinned by.** `EventBridgeTest::it_raises_the_events_of_every_previous_model_over_one_current_record()` and `AffectedRecordsTest::it_finds_the_previous_records_of_every_other_map_over_a_current_record()`. `MirrorIndexTest::it_lists_the_maps_and_aliases_of_each_current_table_and_of_no_view_or_retained_table()` pins `MirrorIndex::forRelation()`, and `KeyTranslatorTest::it_lists_every_previous_key_over_a_primary_key_in_key_order()` pins `KeyTranslator::previousKeys()`.

## INV-7: The update trigger writes only what changed

**Rule.** The update trigger writes a table only when one of its attributes changed, and a column's value only when its own attribute changed. Its `UPDATE` assigns every mapped column of that table, retained table included, and an unchanged column gets its own stored value. So PostgreSQL fires an `UPDATE OF` trigger on that table for a write that did not change its column. `compatibility:check` warns of each such trigger without a `WHEN` clause, and its exit status stays the same.

**Why.** An unchanged table keeps its rows as they are, and fires none of its triggers. A column whose attribute did not change keeps the value stored now. PostgreSQL fires an `UPDATE OF` trigger for each column in the `SET` list, changed or not. The SQL stays as it is, and the check names each trigger that needs a guard. The event bridge compares rows, not `SET` lists, so its events are unaffected.

**Code that must agree.** `SqlFragments::changed()`, `Compiler::updateTable()`, `Compiler::updateDependent()` and `Compiler::update()`. `DriftQueries::unguardedColumnTriggers()` reads the same `ResolvedMap::assignmentsTo()` targets as `Compiler::update()`, for each current table and for the retained table, and `CheckDrift::handle()` prints its result without a change to the exit status.

**Pinned by.** `InstallerTest::it_writes_only_the_tables_whose_attributes_changed()`. `CheckDriftTest::it_warns_of_each_unguarded_update_of_trigger_on_a_mapped_column_and_still_passes()` pins the warning, with a trigger on a written retained column, a guarded trigger, an unmapped column on a current table and on the retained table, a map's own trigger and a trigger without a column list left out. `EventBridgeTest::it_raises_nothing_for_a_write_that_changes_no_attribute_of_the_other_side()` pins the result the bridge sees. `SqlFragmentsTest::it_tells_whether_any_listed_column_changed_counting_null_as_a_value()` pins `SqlFragments::changed()`.

## INV-8: Each stored column has one owner

**Rule.** At most one attribute writes a given column, and no attribute writes the primary foreign key or a designation column. A fill from `PrimaryCurrentModel::fillWhenCreatedFromPrevious()` or `DependentCurrentModel::fillWhenCreatedFromPrevious()` never sets a column an attribute writes.

**Why.** The update trigger writes each column from one attribute's change. The map maintains the link and the designation itself.

**Code that must agree.** `ResolvedMap::validateAttributes()`, `Compiler::validateColumns()` and `Compiler::insertValues()`.

**Pinned by.** `ResolvedMapTest::it_refuses_two_attributes_that_own_one_stored_column()`, `ResolvedMapTest::it_refuses_an_attribute_the_tables_cannot_store()` and `DependentCurrentModelTest::it_refuses_an_attribute_that_writes_the_designation()`. `CompilerTest::it_refuses_at_compile_time_a_map_the_tables_cannot_store()` pins the fill collision, in its `primary_fill_collision` and `dependent_fill_collision` cases.

## INV-9: Each map owns a prefix no other map's prefix begins

**Rule.** Every trigger and function of a map starts with `compatibility__<name>__` and fits in 63 characters. A map name has at most 24 lowercase letters, digits and single underscores, and does not end in an underscore. The Installer drops only the prefixed triggers on the current schema's tables, the prefixed functions of that schema and the view in that schema.

**Why.** The Installer finds a map's triggers and functions by prefix and schema alone. A name with a double or trailing underscore could make one map's prefix begin another's. Uninstalling one map would then drop the other's objects. Without the schema, an install in one schema would drop the same map's triggers in another, or a trigger on another schema's table.

**Code that must agree.** `ResolvedMap::of()` checks the name. `ResolvedMap::objectPrefix()`, `ResolvedMap::functionName()`, `ResolvedMap::refuseFunctionName()` and `ResolvedMap::designateFunctionName()` build every name, through the private `ResolvedMap::object()`. `Installer::dropObjects()` matches the prefix through `SqlFragments::like()`. It drops a trigger only when its table and its function are both in `current_schema()`, and a function only when it is in that schema. It names the schema through `to_regnamespace(quote_ident(current_schema()))`, so a schema whose name needs quoting resolves. The private `Installer::relation()` looks the view up in that schema alone. `Installer::install()` refuses when it finds one, `Installer::recompile()` replaces it only when it finds one, and `Installer::uninstall()` drops it only when it finds one. `Compiler` creates every function in that same schema.

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

**Code that must agree.** `ResolvedMap::locate()` adds the designation to the condition. `Compiler::designateFunction()` fills an empty designation when an insert or an update makes a dependent row readable. `Compiler::triggers()` attaches it `AFTER INSERT OR UPDATE`. `Compiler::designate()` sets the designation for rows the view creates. `Compiler::updateDependent()` refuses, and `Compiler::validateColumns()` requires the column. `DriftQueries::brokenDesignations()` reports a broken designation, and an empty one whose record has a readable row.

**Pinned by.** Every test in `DependentCurrentModelTest`, for example `DependentCurrentModelTest::a_previous_model_write_to_a_dependent_attribute_is_refused_while_the_designated_row_is_not_readable()`. `DependentCurrentModelTest::a_dependent_row_that_becomes_readable_through_an_update_fills_an_empty_designation()` pins the update. `DriftQueriesTest::it_finds_an_empty_designation_whose_record_has_a_readable_row()` pins the empty designation's finding.

A record that already exists at install time has no designation. [Adopting a map](adopting-a-map.md) describes the backfill.

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

**Code that must agree.** `EventReplayer::ing()`, `EventReplayer::changed()` and `EventReplayer::equivalent()`. `EventReplayer::equivalent()` compares by the attribute's cast, or else by its column type, which `EventReplayer::changed()` reads through `ColumnCatalog` only when a value changed. A numeric cast or column compares two numbers through `EventReplayer::number()`, by their exact decimal value, never through a float. So a change below float precision still counts. A JSON cast or a `json` or `jsonb` column compares the decoded JSON, through the private `EventReplayer::json()`. An object and an array differ, empty or nested, and the order of an object's keys does not count. Every other attribute, a text column included, compares exactly, so `'1'` and `'001'` differ. `CompatibilityWriteRefused::byMirroredListener()` and `CompatibilityWriteRefused::byMirroredChange()` build the refusals.

**Pinned by.** `EventBridgeTest::it_rolls_back_the_whole_write_when_a_mirrored_listener_refuses()` and `EventBridgeTest::it_refuses_a_mirrored_listener_that_changes_the_mirrored_record()`. `EventBridgeTest::it_refuses_a_mirrored_listener_change_to_a_number_that_a_float_cannot_tell_apart()` and `EventBridgeTest::it_accepts_a_mirrored_listener_that_rewrites_a_number_as_the_same_value()` pin the number comparison. `EventReplayerTest::it_refuses_the_write_when_a_mirrored_listener_of_an_ing_event_returns_false()`, `EventReplayerTest::it_lets_a_mirrored_listener_of_an_ed_event_return_false()`, `EventReplayerTest::it_refuses_the_write_when_a_mirrored_listener_changes_the_record()`, `EventReplayerTest::it_refuses_a_listener_change_to_a_number_that_a_float_cannot_tell_apart()` and `EventReplayerTest::it_accepts_a_listener_rewrite_to_the_same_number_in_a_numeric_column_or_the_same_json_in_a_json_column()` pin the same rule on `EventReplayer::replay()` alone. `EventReplayerTest::it_refuses_a_listener_change_to_a_text_column_that_reads_as_the_same_number_or_json()` and `EventReplayerTest::it_accepts_a_listener_rewrite_to_the_same_value_of_a_text_column_cast_to_a_number_or_to_json()` pin the column type and the cast. `EventReplayerTest::it_refuses_a_listener_change_of_a_json_column_between_an_object_and_an_array()` and `EventReplayerTest::it_accepts_a_listener_rewrite_of_a_json_column_that_only_reorders_the_keys_of_its_objects()` pin the JSON kinds and key order.

## INV-16: A replayed instance never begins a pending write

**Rule.** The bridge ignores the beginning events of an instance it is replaying. A native write inside a mirrored listener begins its own pending write, on top of the connection's stack.

**Why.** A replayed instance's events describe a write that already happened. Beginning a pending write for it would lock and read records for a statement that never comes.

**Code that must agree.** `EventReplayer::isReplaying()` and `EventReplayer::replay()`, `EventBridge::begin()` and `EventBridge::top()`.

**Pinned by.** `EventBridgeTest::it_mirrors_a_write_a_mirrored_listener_makes_as_a_change_of_its_own()` and `EventBridgeTest::it_runs_an_excluded_listener_for_a_native_write_inside_a_mirrored_event()`. `EventReplayerTest::it_reports_only_the_instance_it_replays_as_replaying_and_only_while_it_replays()` and `EventReplayerTest::it_stops_reporting_an_instance_as_replaying_once_a_listener_refuses_it()` pin `EventReplayer::isReplaying()`.

## INV-17: A delete completes on an UPDATE

**Rule.** A pending delete accepts an `UPDATE` of the model's table as its statement, when that `UPDATE` sets the deleted-at column. [INV-33](#inv-33-a-pending-write-completes-only-on-its-own-statement) states the full rule.

**Why.** Eloquent sends a soft delete as an `UPDATE` of the deleted-at column.

**Code that must agree.** `ModelEvent::operation()`, `Operation::accepts()`, `ParsedWrite::of()` and `PendingMirroredWrite::expects()`.

**Pinned by.** `EventBridgeTest::it_raises_the_soft_delete_and_restore_events_through_the_primary_current_model()`. `OperationTest::it_accepts_its_own_operation_and_a_delete_accepts_the_update_of_a_soft_delete()`, `ParsedWriteTest::it_parses_the_insert_update_soft_delete_and_force_delete_eloquent_emits()`, `PendingMirroredWriteTest::it_expects_the_operation_of_its_model_event_and_a_soft_delete_for_a_delete()` and `ModelEventTest::it_maps_each_beginning_event_to_its_operation_and_every_other_case_to_none()` pin each step.

## INV-18: An excluded listener skips mirrored dispatches only

**Rule.** An excluded listener is skipped only while the bridge dispatches for the instance it replays. It runs for every native event, including one raised inside a mirrored listener.

**Why.** An event mirror excludes a listener from writes through the other side, not from the model's own writes.

**Code that must agree.** `EventReplayer::dispatch()`, `EventReplayer::mask()`, `EventReplayer::guard()` and `EventReplayer::unmask()`. The `Exclusion` is held per subject. `ExcludedListener::matches()` matches the listener. `EventReplayer::dispatch()` masks the listeners on the real dispatcher behind an `EventFake`, and dispatches through the model's dispatcher. So a faked event is recorded, and none of its listeners runs.

**Pinned by.** `EventBridgeTest::it_skips_an_excluded_listener_for_mirrored_events_only()`, `EventBridgeTest::it_skips_an_excluded_listener_and_records_each_mirrored_event_once_through_a_decorating_dispatcher()`, `EventBridgeTest::it_skips_an_excluded_listener_for_a_mirrored_create_through_a_decorating_dispatcher_and_runs_it_for_a_native_create()` and `EventBridgeTest::it_runs_an_excluded_listener_for_a_native_write_inside_a_mirrored_event()`. `EventReplayerTest::it_skips_an_excluded_listener_for_its_replayed_event_only()` and `EventReplayerTest::it_skips_an_excluded_listener_under_a_fake_that_leaves_model_events_alone()` pin the masking, and `ExcludedListenerTest` pins `ExcludedListener::matches()`. `EventBridgeTest::it_records_a_faked_mirrored_event_and_runs_none_of_its_listeners_when_the_map_excludes_a_listener()` and `EventReplayerTest::it_records_a_faked_event_without_running_its_listeners_when_the_map_excludes_a_listener()` pin a faked event.

## INV-19: A failed write at level 0 releases its transaction

**Rule.** A failed write that began at transaction level 0 is closed by the first of four events. They are the next statement, the next bridged write, a queue worker's next loop and application termination.

**Why.** The bridge opened that transaction, and nothing else would close it. PostgreSQL refuses every further statement in an aborted transaction.

**Code that must agree.** The `beforeExecuting` callback in `EventBridge::watch()`, `EventBridge::finishFailed()`, `EventBridge::releaseFailed()` and `EventBridge::begin()`. `Provider::boot()` listens for `Looping` and the terminating callbacks. Only a write whose statement started holds a transaction, so `EventBridge::finishFailed()` reaps only such a write; [INV-34](#inv-34-a-pending-write-opens-at-its-statement) records why.

**Pinned by.** `EventBridgeOutsideTransactionTest::it_rolls_back_its_transaction_before_the_next_statement_after_a_database_refusal_outside_a_transaction()`, `EventBridgeOutsideTransactionTest::it_releases_the_transaction_left_by_a_database_refusal_on_the_next_bridged_write()`, `EventBridgeOutsideTransactionTest::it_rolls_back_its_transaction_when_a_queued_bridged_write_fails_so_the_worker_records_the_failed_job()` and `EventBridgeOutsideTransactionTest::it_releases_the_transaction_left_by_a_database_refusal_when_the_application_terminates()`.

## INV-20: Inside a caller's transaction, a failure behaves as a native one

**Rule.** Inside a caller's transaction, the bridge works in a savepoint Laravel does not count. A PHP failure rolls back only that savepoint. A failed statement leaves the caller's transaction aborted, as a native failed statement would.

**Why.** The caller owns its transaction and decides how to recover from a failed statement.

**Code that must agree.** The private `EventBridge::open()` names the savepoint, and `EventBridge::close()` rolls it back or releases it. `EventBridge::complete()` runs the replay inside `Connection::transaction()`. The `beforeExecuting` callback in `EventBridge::watch()` and `EventBridge::finishFailed()` drop a savepoint write whose statement failed, without a rollback.

**Pinned by.** `EventBridgeOutsideTransactionTest::it_leaves_a_caller_transaction_aborted_after_a_database_refusal_inside_it_as_a_native_write_would()`, `EventBridgeTest::it_leaves_the_callers_transaction_usable_after_a_database_refusal()` and `EventBridgeTest::it_rolls_back_the_write_and_its_after_commit_work_when_a_mirrored_listener_throws()`.

## INV-21: A faked dispatcher cannot hide a mirrored write

**Rule.** A write to a mirrored relation throws `CompatibilityEventMirrorBypassed` when the model dispatcher is an `EventFake`. The fake recorded the beginning event instead of running the bridge's listener, so no pending write began. The guard keeps its count per fake and per relation, so each relation's bypass throws.

**Why.** A test that fakes every event and writes through a mirrored model proves nothing about the other side.

**Code that must agree.** `EventBridge::guard()`, `EventBridge::$faked`, `MirrorIndex::modelsOf()`, `MirrorIndex::mapsOf()` and `ModelEvent::beginning()`. Inside `EventBridge::withoutMirroring()`, the guard still stores each count and only skips the throw. So a faked write with the mirror off does not make the next raw write throw.

**Pinned by.** `EventBridgeTest::it_fails_loudly_when_a_test_fakes_the_model_events()`, `EventBridgeTest::it_fails_loudly_for_each_relation_a_faked_dispatcher_bypasses()`, `EventBridgeTest::it_mirrors_under_a_fake_that_leaves_model_events_alone()`, `EventBridgeTest::it_switches_the_mirror_off_only_when_a_test_says_so()` and `EventBridgeTest::it_lets_a_raw_write_through_after_a_faked_write_with_the_mirror_off()`. `MirrorIndexTest::it_lists_the_model_classes_over_a_relation_once()` and `MirrorIndexTest::it_lists_the_maps_over_a_relation_once()` pin the lookups the guard counts by.

## INV-22: Compiled identifiers are full words

**Rule.** Every name the compiler adds is a full word: `previous_row`, `affected_rows`, `key_<alias>`, `created_key`, the object prefix and the function suffixes. An alias is a lowercase identifier other than `new`, `old` and the PostgreSQL reserved words. It is also no variable the triggers declare: not `previous_row`, `affected_rows` or `created_key`, and no name that starts with `key_`.

**Why.** A reader of the compiled SQL should not decode abbreviations. A reserved word as an alias would fail the install with a syntax error. An alias that names a variable makes PL/pgSQL refuse every reference to it as ambiguous, so every update would fail.

**Code that must agree.** `ResolvedMap::of()`, `ReservedWords::POSTGRESQL` and `ResolvedMap::VARIABLES`. `Compiler::variables()` names `previous_row`, `affected_rows` and `key_<alias>`, and `Compiler::primaryInsertFunction()` names `created_key`.

**Pinned by.** `InstallerTest::it_compiles_only_full_word_aliases_variables_and_object_names()` and `ResolvedMapTest::it_refuses_an_alias_that_is_a_reserved_postgresql_word()`. `ResolvedMapTest::it_refuses_an_alias_that_names_a_variable_the_compiled_triggers_declare()` pins the variables. `ReservedWordsTest::it_lists_every_word_the_server_reserves_as_an_alias()` checks `ReservedWords::POSTGRESQL` against the server. The first compiles the workbench maps, whose aliases are full words too. An application chooses its own aliases.

## INV-23: A refused-state check is serialized per record

**Rule.** A refuse function on a dependent's table or the retained table first takes `FOR KEY SHARE` on the primary row its row links, then `pg_advisory_xact_lock()`. The advisory lock's key is the refuse function's name, a colon and the row's primary foreign key, hashed with `hashtextextended()`. A refuse function on the primary table takes no lock.

**Why.** A condition that reads other rows of the same record would otherwise miss a concurrent writer's uncommitted row, and both writers would commit the state. The key must name both parts. Without the function name, two states would wait on each other. Without the primary foreign key, every writer of the table would wait on one lock. The primary row lock comes first so that the refused-state lock joins INV-1's order. INV-1 records the deadlock path that remains. A primary row has no primary foreign key, so the primary table stays unserialized. [0016](decisions/0016-a-refused-state-on-the-primary-table-is-not-serialized.md) records that limit.

**Code that must agree.** `Compiler::refuseFunction()` builds the lock from `ResolvedMap::refuseFunctionName()`, `ResolvedMap::dependent()` and `ResolvedMap::primaryForeignKey()`, or from the previous model's primary foreign key. The two locks are the function's first statements, before the `IF EXISTS` check. `Compiler::function()` declares no volatility, so the check after the wait reads a new snapshot under READ COMMITTED.

**Pinned by.** `InstallerTest::it_makes_a_concurrent_write_wait_for_the_refused_state_check_of_the_same_record()`, which writes from a second connection. `InstallerTest::it_does_not_deadlock_a_write_through_the_view_against_a_writer_holding_the_refused_state_lock()` pins the primary row lock. No test pins the primary-table limit or the REPEATABLE READ limit.

## INV-24: Each Installer method is atomic

**Rule.** `Installer::install()`, `Installer::recompile()` and `Installer::uninstall()` each run every statement inside one `Connection::transaction()`. Inside a caller's transaction, that is a savepoint.

**Why.** Each method drops the map's triggers before it creates or drops anything else, and a recompile drops the view too. A failure part way would leave a half-installed map, or a map without its triggers. A new primary row would then get no retained row. A concurrent writer waits on the dropped triggers' locks until the commit.

**Code that must agree.** `Installer::install()` wraps `Installer::apply()` and `Installer::copyPrivileges()`. `Installer::recompile()` wraps the private `Installer::viewGrants()`, `Installer::apply()` and the grants it restores. `Installer::uninstall()` wraps `Installer::dropObjects()` and the `DROP VIEW`. The checks in `Installer::install()` for an existing view and for views that read the retained table stay outside, because each throws before any change. Every refusal in `Installer::apply()` throws inside the transaction, before its first change.

**Pinned by.** `InstallerTest::it_leaves_nothing_behind_when_an_install_fails()`, `InstallerTest::it_keeps_the_installed_compatibility_map_when_a_recompile_fails()` and `InstallerTest::it_keeps_the_installed_compatibility_map_when_an_uninstall_fails()`. `InstallerTest` runs outside a test transaction, so these tests see a real commit and rollback.

## INV-25: A delete through the previous model mirrors every row it removes

**Rule.** A delete through the previous model that removes the row mirrors every dependent row under the record's primary foreign key. That includes a row another transaction commits before the bridge locks the primary row, when the dependent's primary foreign key has a foreign key constraint or its table has a refuse function ([INV-1](#inv-1-locks-follow-one-order) states the limit). A soft delete mirrors only the rows the view reads.

**Why.** The delete trigger removes every dependent row of the primary row, including rows the view does not read. Each removed row must raise its own delete events.

**Code that must agree.** `Compiler::deleteFunction()` deletes by primary foreign key. `KeyTranslator::dependentKeys()` selects by the same key. The private `AffectedRecords::removed()` turns those keys into records when the private `AffectedRecords::removes()` holds. `AffectedRecords::of()` calls it to find the records. `AffectedRecords::before()` calls `AffectedRecords::of()` a second time under the locks, so it finds newcomers too. A change to which rows the trigger deletes must change `KeyTranslator::dependentKeys()` with it.

**Pinned by.** `EventBridgeTest::it_raises_the_force_delete_events_of_every_dependent_row_a_previous_model_force_delete_removes()`. `EventBridgeOutsideTransactionTest::it_raises_the_force_delete_events_of_a_dependent_row_committed_before_the_primary_record_is_locked()` pins the newcomer, which a second connection commits. `AffectedRecordsTest::it_finds_every_dependent_row_a_previous_model_delete_removes()`, `AffectedRecordsTest::it_keeps_a_soft_delete_to_the_dependent_rows_the_previous_model_reads()` and `KeyTranslatorTest::it_lists_every_dependent_row_under_the_primary_row_of_a_previous_key()` pin the rows the bridge finds. `AffectedRecordsTest::it_locks_a_dependent_row_that_appears_under_the_first_locks_after_them_and_snapshots_it()` pins the newcomer's lock and snapshot.

## INV-33: A pending write completes only on its own statement

**Rule.** A pending write opens and completes only on the statement Eloquent sends for its model. The model's own write method must issue that statement, with no event dispatch between that method's frame and the statement. An update or a delete must compare the model's key with `=` in its `WHERE` clause, and bind there the key the model saves by. An insert must name the model's attributes as its columns, in their order. An `UPDATE` for a pending update must set exactly the model's dirty attributes at that statement. An `UPDATE` for a pending soft delete must set the deleted-at column, and only dirty attributes. Both count the updated-at column as dirty. An `UPDATE` for a pending increment or decrement must set exactly the columns of that call and of its extra array, and the updated-at column when the model uses timestamps.

**Why.** A listener that runs after the bridge's own listener can write the same table first. A match on the relation and the operation alone let that write claim the pending write. The model's own statement then reached the database with no events mirrored, and no lock taken. A key alone still let a later listener's write to the same record claim it. A placeholder count that included a `?` inside a literal, an identifier or a comment shifted the key's placeholder. An update that set a raw `'Why?'` then matched nothing, and mirrored nothing. Eloquent's builder adds the updated-at column to an update in the same second without marking it dirty. So the rule counts it as dirty on both sides. An increment or a decrement sets only its own columns, its extra columns and the updated-at column. A model that also held an unrelated unsaved change, such as a title, sent an `UPDATE` that matched no dirty set. That statement then ran with no events mirrored, no lock and no snapshot. A rule that accepted a subset of the dirty attributes would let a later listener's write claim the pending write. So the rule reads the columns from the arguments of the call in progress. It requires the updated-at column there, because Eloquent's builder always adds it to an increment of a model that uses timestamps. These checks still let a listener's statement claim the pending write when it named the model's columns, or set its dirty attributes or its increment's columns by its key. Eloquent runs every listener inside the events dispatcher, and it sends the model's own statement outside it. So the rule refuses a statement that runs under a dispatcher frame inside the write.

**Code that must agree.** `PendingMirroredWrite::expects()`, which reads `ParsedWrite::$columns` and `ParsedWrite::$wherePlaceholders` from `ParsedWrite::of()`. The private `PendingMirroredWrite::setsItsOwnChange()` compares an update's columns with `Model::getDirty()`, or with `PendingMirroredWrite::$incrementColumns` for an increment or a decrement. The `beforeExecuting` callback in `EventBridge::watch()` opens a write only when the private `EventBridge::dispatcherFramesSinceItsWrite()` counts no frame between this statement and the write's frame whose object is an `\Illuminate\Contracts\Events\Dispatcher`. The events dispatcher, an `EventFake` and a decorating dispatcher each implement that contract, and Laravel 12 and 13 both dispatch model events through it. `EventBridge::dispatcherFramesSinceItsWrite()` sets `PendingMirroredWrite::$incrementColumns` through the private `EventBridge::incrementColumns()`. That method reads the column, or the keys of the columns array, and the keys of the extra array from the arguments of `Model::incrementOrDecrement()` or `Model::incrementOrDecrementEach()`. Laravel 12 and 13 give `Model::incrementOrDecrement()` the same parameters, and Laravel 12 has no `Model::incrementOrDecrementEach()`. `ParsedWrite::of()` reads an update's `SET` targets into `ParsedWrite::$columns`, and none when an assignment sets more than one column. `ParsedWrite::of()` numbers only the `?` that PDO binds. It skips string literals, `E''` literals, dollar-quoted literals, quoted identifiers, comments and each `??`. A `--` comment ends at a line feed or a carriage return, and a dollar-quoted literal closes only on a tag of the same case. A statement the scan cannot read, such as a comment nested ten thousand deep, gives no `ParsedWrite`, so nothing pretends to know it. Its two callers pass the statement's bindings: the `beforeExecuting` callback in `EventBridge::watch()` and `EventBridge::handleQuery()`. The save key is the model's raw original key, or else its current key, as Eloquent's save query binds it.

**Pinned by.** `EventBridgeTest::it_mirrors_a_write_on_its_own_statement_and_not_on_a_later_listeners_write_to_the_same_table()`, for an update and a soft delete. Its listener binds the source model's key as a value, which the `WHERE` placeholder rule ignores. `EventBridgeTest::it_mirrors_a_create_on_its_own_insert_and_not_on_a_later_listeners_insert_into_the_same_table()` pins an insert. `PendingMirroredWriteTest::it_expects_an_update_or_delete_only_when_the_statement_binds_its_models_key_for_saving()`, `PendingMirroredWriteTest::it_expects_an_update_that_binds_the_key_the_model_was_loaded_with_after_the_key_changes()` and `PendingMirroredWriteTest::it_expects_an_insert_only_when_it_names_the_columns_of_its_models_attributes()` pin `PendingMirroredWrite::expects()`. `EventBridgeTest::it_mirrors_a_write_on_its_own_statement_and_not_on_a_later_listeners_write_to_the_same_record()` pins a listener's write by the same key, for an update and a soft delete. `EventBridgeTest::it_mirrors_an_increment_whose_statement_also_sets_the_updated_at_column()` and `EventBridgeTest::it_mirrors_the_soft_delete_of_a_model_that_holds_an_unsaved_change()` pin two statements of Eloquent's own. `EventBridgeTest::it_mirrors_an_increment_or_decrement_of_a_model_that_holds_an_unsaved_change()` and `EventBridgeTest::it_mirrors_an_increment_or_decrement_of_each_column_of_a_model_that_holds_an_unsaved_change()` pin an increment and a decrement beside an unsaved change, with and without an extra array. `EventBridgeOutsideTransactionTest::it_locks_and_mirrors_an_increment_or_decrement_of_a_model_that_holds_an_unsaved_change_outside_a_transaction()` and `EventBridgeOutsideTransactionTest::it_locks_and_mirrors_an_increment_or_decrement_of_each_column_of_a_model_that_holds_an_unsaved_change_outside_a_transaction()` pin their lock outside a transaction. `EventBridgeTest::it_mirrors_an_increment_on_its_own_statement_and_not_on_a_later_listeners_write_to_the_same_record()` pins a listener's write during an increment, including one that sets the increment's columns and the updated-at column. `EventBridgeTest::it_mirrors_a_save_on_its_own_statement_and_not_on_a_later_listeners_write_of_some_or_all_of_its_changes()` pins one during a save that sets some or all of its dirty attributes. `EventBridgeTest::it_mirrors_a_create_on_its_own_insert_and_not_on_a_later_listeners_insert_into_the_same_table()` also pins a listener's insert that names exactly the model's columns. `EventBridgeOutsideTransactionTest::it_locks_and_mirrors_a_write_on_its_own_statement_and_not_on_a_listeners_update_of_the_same_columns_outside_a_transaction()` pins the lock the model's own statement takes, for an increment and a save. `PendingMirroredWriteTest::it_expects_an_update_that_sets_exactly_its_models_changes_and_a_soft_delete_that_sets_only_changed_columns_and_the_deleted_at_column()` pins the comparison. `PendingMirroredWriteTest::it_expects_an_increment_that_sets_exactly_its_columns_and_the_updated_at_column_whatever_else_its_model_changed()` and `PendingMirroredWriteTest::it_expects_an_increment_of_a_model_without_timestamps_to_set_only_its_columns()` pin the comparison for an increment. `ParsedWriteTest::it_parses_the_columns_an_insert_names_or_an_update_sets_and_none_for_a_delete()`, `ParsedWriteTest::it_parses_the_placeholder_of_each_column_its_where_clause_compares_for_equality()` and `ParsedWriteTest::it_counts_only_the_placeholders_outside_literals_identifiers_and_comments()` pin the parse. `ParsedWriteTest::it_counts_the_placeholders_after_a_literal_identifier_or_comment_of_any_length()` and `ParsedWriteTest::it_parses_nothing_when_the_scan_of_the_statement_fails()` pin the long and the unreadable statement. `EventBridgeTest::it_mirrors_an_update_that_sets_a_raw_expression_holding_a_question_mark()` and `EventBridgeTest::it_mirrors_an_update_that_sets_a_raw_literal_too_long_for_the_scan_to_backtrack()` pin it end to end.

The rule has two limits. A model whose own save query compares its key other than with `=` matches nothing, and mirrors nothing. A statement that the model's own write method issues outside a dispatch, before its own statement, still matches when it passes the column and key checks. An override of a write method that runs such a statement before it calls its parent is one example.

## INV-34: A pending write opens at its statement

**Rule.** A beginning event only records a pending write. When that write's own statement starts, the bridge opens its transaction or savepoint, locks the affected records and reads them. It opens a write only while its model's insert, update or delete is still in progress, and forgets it otherwise. `EventBridge::finishFailed()` reaps only a write whose statement started. Before each statement, the bridge closes every write on top of the stack whose statement failed, then tests the write below against this statement.

**Why.** Code can throw between the beginning event and the statement: a cast, the encrypter or a later listener. A transaction opened at the event would then outlive the write, and hold its locks for the rest of the request. A listener on the beginning event can also write another mapped model. Its write would otherwise reap the outer write before the outer statement ran, and the outer write would mirror nothing. When that inner write fails and the listener catches the failure, the outer statement comes next. A callback that stopped after it closed the inner write would let the outer statement run with nothing opened. A save that a listener vetoes, or that throws before its statement, leaves its write on the stack. A later write without events to the same record would otherwise complete it, and the bridge would mirror a write the application made without events.

**Code that must agree.** `EventBridge::begin()` pushes the `PendingMirroredWrite`. The `beforeExecuting` callback in `EventBridge::watch()` loops over the top of the stack. It closes or forgets each write whose statement started, and forgets each write whose model the private `EventBridge::dispatcherFramesSinceItsWrite()` no longer finds. It then calls the private `EventBridge::open()` when the top write expects this statement and no dispatcher frame lies between them, which sets `PendingMirroredWrite::$statementSent`. A statement under a dispatcher frame leaves the write on the stack, unopened. `EventBridge::dispatcherFramesSinceItsWrite()` looks for the model as the object of a frame of `EventBridge::WRITING_METHODS` in `debug_backtrace()`. It counts the frames it passes on the way whose object is an `\Illuminate\Contracts\Events\Dispatcher`. Those are the Eloquent methods that fire a beginning event and send its statement. A new Eloquent write method that fires one must join the list. The call stack carries each frame's arguments. `EventBridge::dispatcherFramesSinceItsWrite()` keeps the column names of the matching frame only, in `PendingMirroredWrite::$incrementColumns`, and none for a frame that is not an increment or a decrement. [INV-33](#inv-33-a-pending-write-completes-only-on-its-own-statement) states how `PendingMirroredWrite::expects()` uses them. `EventBridge::finishFailed()` stops at a write whose statement has not started. `EventBridge::finish()` forgets such a write on its model's finishing event, and `EventBridge::releaseFailed()` forgets every such write.

**Pinned by.** `EventBridgeOutsideTransactionTest::it_opens_no_transaction_and_takes_no_lock_when_a_listener_throws_before_the_statement()`, with an updating listener after the bridge's own, and a later write without events that mirrors nothing. `EventBridgeTest::it_mirrors_nothing_for_a_later_write_without_events_to_the_record_of_a_vetoed_save()` pins a vetoed save. `EventBridgeOutsideTransactionTest::it_mirrors_both_writes_when_a_beginning_listener_writes_another_mapped_model_outside_a_transaction()` and `EventBridgeTest::it_mirrors_both_writes_when_a_beginning_listener_writes_another_mapped_model_inside_a_callers_transaction()` pin the nested write at both levels. `EventBridgeOutsideTransactionTest::it_mirrors_the_outer_write_when_a_beginning_listener_catches_a_refused_write_to_another_mapped_model_outside_a_transaction()` and `EventBridgeTest::it_mirrors_the_outer_write_when_a_beginning_listener_catches_a_refused_write_to_another_mapped_model_inside_a_callers_transaction()` pin a failed nested write.

A write whose statement never comes stays on the stack until the connection's next statement, its model's finishing event, the next `Looping` event or termination. It holds no transaction and no lock. The call-stack test runs only while the top write's statement has not started.

## INV-35: A pending write leaves the stack only once its close finishes

**Rule.** `EventBridge::close()` forgets a pending write after its commit, rollback or savepoint release finishes. When a commit at level 0 fails, it rolls the connection back to the write's level, forgets the write and rethrows.

**Why.** A `COMMIT` can fail, for example on a deferred constraint or a lost connection. Laravel then still counts the bridge's transaction. A write forgotten before its commit would leave that level with nothing to close it.

**Code that must agree.** `EventBridge::close()`, and Laravel's `Connection::rollBack()`, which resets the level whether or not PDO still holds the transaction.

**Pinned by.** `EventBridgeOutsideTransactionTest::it_rolls_back_a_mirrored_write_whose_commit_fails_and_leaves_the_connection_outside_a_transaction()`, which fails the commit with a deferred constraint trigger.

## INV-36: An attribute without a btree equality compares as text

**Rule.** The triggers compare an attribute as text when its type has no default btree equality operator. That covers the read-only check, `SqlFragments::changed()`, each `CASE WHEN` in `Compiler::update()` and the conflict check's `ROW()`. `json` and `xml` are such types, and so is a domain over one. A composite type compares as text too.

**Why.** `json` has no `=` operator, so `IS DISTINCT FROM` fails on every update that compares it, and install still succeeds. Text keeps a whitespace or key-order change the caller wrote. A cast to `jsonb` would discard that change.

**Code that must agree.** `ColumnCatalog::equatable()` follows a domain to its base type and an array to its element type. The private `Compiler::compared()`, `Compiler::comparesAsText()` and `Compiler::comparedAsText()` apply it. `SqlFragments::changed()` takes the columns it compares as text. A retained attribute takes its column's catalog type, and a mapped attribute its `Attribute::asType()`.

**Pinned by.** `CompilerTest::it_compares_a_json_attribute_by_its_text_in_every_write_through_the_view()`, with a writable and a read-only retained `json` attribute and a mapped `json` attribute. `ColumnCatalogTest::it_tells_whether_a_type_has_a_default_btree_equality_operator()` and `SqlFragmentsTest::it_tells_whether_a_column_compared_as_text_changed_by_its_text()` pin the parts.

## INV-37: A trigger function runs only for its own relation

**Rule.** Every compiled function refuses with CM001 when `TG_RELID` names a relation other than its own or a partition of its own. Install and recompile revoke `EXECUTE` on each function from `PUBLIC`.

**Why.** The functions are `SECURITY DEFINER`. Any role that can create a temporary table could otherwise attach one as a trigger there. It would then run the body with the owner's rights, for example to delete retained rows. PostgreSQL checks `EXECUTE` when a trigger is created, not when it fires, so the revoke leaves the map's own triggers working. PostgreSQL clones a row trigger on a partitioned table onto each partition, and the clone fires with the partition as `TG_RELID`. Only the partitioned table's owner can attach a partition, so accepting the partition root admits no other role's table.

**Code that must agree.** `Compiler::function()` writes the check as the first statement of every body, and accepts a relation whose `pg_partition_root()` is its own. `Compiler::compile()` adds a `REVOKE` after each function. The relation is the view for the insert, update and delete functions, and the primary table for the primary insert and primary delete functions. A refuse function's relation is its checked table, and a designate function's is its dependent's table.

**Pinned by.** `CompilerTest::it_refuses_to_run_a_trigger_function_fired_on_any_relation_but_its_own()`, `CompilerTest::it_refuses_the_primary_delete_function_fired_on_another_table_before_it_deletes_a_retained_row()`, `CompilerTest::it_runs_its_trigger_functions_on_the_partitions_of_a_partitioned_current_table()` and `InstallerTest::it_lets_no_other_role_execute_a_trigger_function_after_an_install_or_a_recompile()`. `InstallerTest::it_lets_a_role_granted_a_retained_column_write_that_column_through_the_view()` pins that a granted role still writes through the view.

## INV-38: The schema refuses a table with row-level security

**Rule.** `Installer::install()` and `Installer::recompile()` refuse a map whose retained table or any current table has row-level security enabled.

**Why.** The view reads with its owner's rights, and the `SECURITY DEFINER` triggers write with them. A role granted the view would read and write past the table's policies, for example into another tenant's rows. The package cannot enforce a caller's policies yet.

**Code that must agree.** The private `Installer::apply()`, before it compiles, for both callers.

**Pinned by.** `InstallerTest::it_refuses_to_install_or_recompile_over_a_table_with_row_level_security()`.

## INV-39: Every insert fills each required column

**Rule.** Compile refuses a `NOT NULL` column without a default that an insert leaves empty. It checks the retained table on a primary insert and on an insert through the view. It checks the primary table and each inserted dependent's table on an insert through the view. An identity or generated column counts as filled.

**Why.** Install would otherwise succeed, and every insert into the current table would then fail with 23502, factories and seeders included.

**Code that must agree.** The private `Compiler::validateFills()` and `Compiler::refuseEmpty()`, with `Column::needsAValue()` and `Column::$generated` from `ColumnCatalog::of()`. The columns they count as filled must match what `Compiler::primaryInsertFunction()`, `Compiler::insertFunction()` and `Compiler::dependentValues()` insert, so both read the private `Compiler::insertAssignmentsTo()`. A dependent counts only when the view inserts its rows, so only when it has an attribute or a fill. A read-only mapped attribute counts, as [INV-49](#inv-49-an-insert-through-the-view-stores-a-read-only-attribute) requires. `Compiler::compile()` runs the check after it builds the functions, so a missing column refuses first. A column with a default counts as filled, so the insert must let that default fill it, as [INV-46](#inv-46-an-insert-through-the-view-keeps-a-not-null-columns-default) requires.

**Pinned by.** `CompilerTest::it_refuses_a_not_null_column_without_a_default_that_an_insert_leaves_empty()`, `CompilerTest::it_counts_a_filled_default_identity_or_generated_column_as_filled_on_every_insert()` and `ColumnTest::it_needs_a_value_from_every_insert_only_when_it_is_not_null_without_a_default_and_not_generated()`.

## INV-40: An insert through the view returns the row as the view reads it

**Rule.** Before `RETURN NEW`, the insert trigger reads every attribute back from the stored rows into `NEW`, as the view reads it.

**Why.** `INSERT ... RETURNING` reports `NEW`. Without the read it reports only what the caller sent: no default, fill or trigger value, and no value a read expression formats.

**Code that must agree.** `Compiler::insertFunction()` reads through `Compiler::read()`, which `Compiler::view()` uses too. The read leaves out the hidden states' filter, so a hidden record still returns its row.

**Pinned by.** `CompilerTest::it_returns_every_attribute_of_an_insert_through_the_view_as_the_view_reads_it()`, with a retained default, a primary fill, a retained trigger and a formatted read.

## INV-41: A recompile replaces the view and keeps its grants

**Rule.** `Installer::recompile()` drops the view and creates it again in its transaction. It saves the view's grants first and grants them again after, a column grant only when the new view has that column. It refuses with `InvalidCompatibilityMap` while another object depends on the view or uses its row type, and names each one. It also refuses, by name, a trigger or a rule on the view that is not the map's own. It never drops with `CASCADE`. The new view carries no grant its default privileges add, as [INV-48](#inv-48-the-new-view-carries-no-grant-its-default-privileges-add) requires.

**Why.** `CREATE OR REPLACE VIEW` cannot drop, retype or reorder a column. A dependent object would block the drop with a raw 2BP01 error, and `CASCADE` would drop that object silently. `DROP VIEW` drops a trigger or a rule on the view silently, even without `CASCADE`.

**Code that must agree.** `Installer::recompile()`, the private `Installer::viewGrants()` and the private `Installer::apply()`. `Installer::apply()` reads `pg_depend` for normal dependencies on the view and its row type, and for automatic ones from a trigger or a rule. A trigger named with the map's object prefix is the map's own, as for `Installer::dropObjects()`. `Installer::viewGrants()` saves a view with no explicit grants as its owner's default privileges, through `acldefault()`. It saves no grant of the recompiling role, which owns the new view.

**Pinned by.** `InstallerTest::it_recompiles_a_map_that_removes_retypes_or_reorders_an_attribute()`, `InstallerTest::it_keeps_every_grant_on_the_view_when_it_recompiles_the_view_anew()`, `InstallerTest::it_leaves_the_views_grants_as_they_are_when_it_recompiles()` and `InstallerTest::it_refuses_to_recompile_while_another_object_depends_on_the_view_and_names_it()`. `InstallerTest::it_refuses_to_recompile_while_an_object_uses_the_views_row_type_or_sits_on_the_view_and_names_it()` pins a function argument, a column, a trigger and a rule. [0022](decisions/0022-a-recompile-replaces-the-view-and-refuses-its-dependents.md) records the decision.

## INV-42: Only the compatibility view reads the retained table

**Rule.** `Installer::install()` refuses while another view or materialized view reads the retained table, and names each one.

**Why.** A view on the old table follows its rename and keeps reading the retained table. It then misses every mapped attribute, and the triggers never see its writes. [Adopting a map](adopting-a-map.md) describes how to point such a view at the compatibility view.

**Code that must agree.** `Installer::install()` finds the readers through `pg_depend` and `pg_rewrite`, before its transaction.

**Pinned by.** `InstallerTest::it_refuses_to_install_while_another_view_reads_the_retained_table_and_names_it()`, with a view and a materialized view.

## INV-43: The event bridge refuses a Parental model

**Rule.** `EventBridge::mirror()` refuses a map that names a class using a trait named Tightenco\Parental\HasChildren, which it finds through Laravel's `class_uses_recursive()`.

**Why.** Parental hydrates a child class, and the child fires its model events under its own class name. The bridge listens by the exact class name, so it would silently mirror none of those writes. Its replays would also build the parent class, not the child.

**Code that must agree.** `EventBridge::mirror()` checks the previous model and every current model before it listens. It resolves the map for its checks, and calls `CompatibilityMaps::register()` only after every check passes. So a refused map never reaches `compatibility:check`. The package adds no dependency on Parental.

**Pinned by.** `EventBridgeTest::it_refuses_to_mirror_a_map_that_names_a_model_whose_children_fire_events_under_their_own_classes()`, with the class as the previous, the primary and a dependent model. It also asserts that the registry and `compatibility:check` hold no map after the refusal. `EventBridgeTest::it_keeps_the_mirrored_map_registered_when_a_second_mirror_of_its_name_is_refused()` pins the same order for the repeated-mirror refusal.

## INV-44: No map expression ends a function body

**Rule.** `Compiler::function()` refuses a body that contains `$compatibility$`, the tag that quotes it.

**Why.** A fill, a condition, a read or a write expression with that tag would end the body early. The rest of the body would then run as SQL of its own when the install runs.

**Code that must agree.** `Compiler::function()`, which builds every function. A default lands in an `ALTER VIEW` statement, never in a body, so the check does not cover it.

**Pinned by.** `CompilerTest::it_refuses_at_compile_time_a_map_the_tables_cannot_store()`, in its `delimiter_in_fill` and `delimiter_in_condition` cases.

## INV-45: A generated column is read-only through the view

**Rule.** `ColumnCatalog::of()` reports no default for a generated column. `Compiler::validateColumns()` refuses a map whose attribute is not read-only and writes a generated column or a `GENERATED ALWAYS` identity column. The message names the attribute and the column, and says to declare it read-only. A `GENERATED BY DEFAULT` identity stays writable. An insert through the view leaves out a read-only attribute, retained or mapped, over a column the database always generates.

**Why.** `pg_attrdef` holds a generated column's expression. Reported as a default, it reaches `ALTER VIEW ... SET DEFAULT`, which PostgreSQL rejects because a default cannot reference a column. So a map that exposes a generated column would fail to install or recompile. A write of a value to a generated column, or to a `GENERATED ALWAYS` identity, fails on every write through the view. Compile refuses the map instead.

An insert that omits a `GENERATED BY DEFAULT` identity attribute takes the identity's next value. `ColumnCatalog::of()` reports that column's default as `nextval(pg_get_serial_sequence('<schema>.<table>', '<column>')::regclass)`. The table name carries its schema, because a view default runs on the writer's search path. A retained or primary attribute takes it as its view default, and [INV-46](#inv-46-an-insert-through-the-view-keeps-a-not-null-columns-default) fills every other insert target.

**Code that must agree.** `ColumnCatalog::of()`, which reads `attgenerated` and `attidentity` into `Column::$default`, `Column::$generated` and `Column::$alwaysGenerated`, and `Compiler::validateColumns()`. `Compiler::defaults()` takes the identity's default. `Compiler::insertFunction()` skips a retained attribute whose column has `Column::$alwaysGenerated`, and the private `Compiler::insertAssignmentsTo()` skips such a read-only mapped attribute. `Column::needsAValue()` keeps counting every generated and identity column as filled, so INV-39 still holds.

**Pinned by.** `ColumnCatalogTest::it_reads_an_identity_or_generated_column_as_generated_and_gives_a_generated_column_no_default()`, `ColumnTest::it_carries_whether_the_database_always_generates_its_value()`, `CompilerTest::it_refuses_a_writable_attribute_whose_column_the_database_always_generates()`, `CompilerTest::it_compiles_and_writes_a_writable_attribute_whose_column_is_a_default_identity()` and `InstallerTest::it_installs_and_recompiles_a_map_that_exposes_a_generated_column_read_only()`. `CompilerTest::it_lets_the_table_number_an_identity_retained_key_that_an_insert_through_the_view_omits()` pins a retained key of either identity kind. `CompilerTest::it_stores_a_read_only_mapped_attribute_on_an_insert_through_the_view_and_still_refuses_a_change_to_it()` pins a read-only mapped attribute over a `GENERATED ALWAYS` identity.

## INV-46: An insert through the view keeps a NOT NULL column's default

**Rule.** An insert through the view writes `COALESCE(<value>, <default>)` to each attribute's target column that is `NOT NULL` and has a catalog default. That covers the primary table, the retained targets of mapped attributes and each dependent's table. A nullable column with a default stores NULL when the insert omits its attribute.

**Why.** The insert writes every target column explicitly, so an omitted attribute's NULL replaced the column's default. A dependent's view column has no default, and a `writesTo()` expression of NULL is NULL. A `NOT NULL` column with a default then failed with 23502, although [INV-39](#inv-39-every-insert-fills-each-required-column) counted it as filled. An explicit NULL failed there anyway, so no insert that succeeded before changes.

**Code that must agree.** `Compiler::insertValues()` wraps each assignment's value, for `Compiler::insertFunction()` and both dependent inserts through `Compiler::dependentValues()`. The update trigger's create branch in `Compiler::updateDependent()` is one of them. `Column::$notNull` and `Column::$default` come from `ColumnCatalog::of()`. A fill from `fillWhenCreatedFromPrevious()` keeps its own expression.

**Pinned by.** `CompilerTest::it_writes_the_default_of_a_not_null_current_column_that_an_insert_through_the_view_leaves_empty()`, with a dependent insert, the update trigger's create branch, a primary `writesTo()` target and a nullable column.

## INV-47: A map's tables resolve in the current schema alone

**Rule.** Compile, install and recompile look up the retained table and every current table in `current_schema()` alone. A table that exists only in a later schema on the search path refuses at compile with `InvalidCompatibilityMap`: "the current table <table> does not exist".

**Why.** Each compiled function pins its `search_path` to the current schema, and its relation check names that schema. A table found further down the search path installed without error. Its trigger then landed on the other schema's table, and every insert there failed with 42P01. `Installer::uninstall()` and `Installer::recompile()` could not remove that trigger.

**Code that must agree.** `ColumnCatalog::of()` and every `to_regclass()` on a map table in `Installer` name the table as `quote_ident(current_schema()) || '.' || <table>`. The private `Installer::relation()` does the same for the view. `Compiler::validateColumns()` refuses a retained or current table that `ColumnCatalog::of()` does not find. `EventReplayer::changed()` reads column types through `ColumnCatalog`, so it also reads the current schema alone.

**Pinned by.** `CompilerTest::it_refuses_a_map_whose_current_table_is_outside_the_schema_it_installs_into()`, with the retained table in the current schema and the current tables in a later one.

## INV-48: The new view carries no grant its default privileges add

**Rule.** After `Installer::install()` or `Installer::recompile()` creates the view, it revokes every grant on the view that its owner does not hold. Install then copies the retained table's grants, and recompile restores the saved grants. So the view carries those grants alone.

**Why.** PostgreSQL applies `ALTER DEFAULT PRIVILEGES` to every view a role creates. A recompile would otherwise give back access a revoke had removed, such as `SELECT` for `PUBLIC`. A default `UPDATE` or `DELETE` grant would also reach the tables through the `SECURITY DEFINER` triggers.

**Code that must agree.** The private `Installer::revokeDefaultPrivileges()`, at the end of the private `Installer::apply()`, for both callers. It reads the grantees from `aclexplode()` of the view's ACL, and leaves the owner's own privileges as they are. `Installer::copyPrivileges()` and the restore in `Installer::recompile()` run after it.

**Pinned by.** `InstallerTest::it_keeps_every_grant_the_default_privileges_add_off_the_view_it_creates()`, for an install and a recompile, with default grants to `PUBLIC` and to a role.

## INV-49: An insert through the view stores a read-only attribute

**Rule.** A read-only attribute cannot change through the view, and an insert through the view still stores its value. A retained attribute stores into its own column, and a mapped attribute into its `storedAs()` column. A mapped attribute with only `readAs()` has no stored column, so it stores nothing. An attribute over a column the database always generates stays out of the insert, as [INV-45](#inv-45-a-generated-column-is-read-only-through-the-view) says. An omitted value takes the column's default, as [INV-46](#inv-46-an-insert-through-the-view-keeps-a-not-null-columns-default) says. The update trigger refuses a change to a read-only attribute with CM001.

**Why.** `Attribute::readOnlyBecause()` clears a mapped attribute's writes, and the insert wrote only those writes. So an Eloquent `create()` with a read-only mapped `created_at` left the stored column NULL, while the model held the value. Nothing raised. A read-only retained attribute was always stored, so the two kinds disagreed.

**Code that must agree.** The private `Compiler::insertAssignmentsTo()` adds each read-only mapped attribute's `storedAs()` target to an alias's writes. `Compiler::insertFunction()` reads it for the primary, retained and dependent inserts, and for each dependent's default `createdWhen()` condition. `Compiler::validateFills()` reads it too, so [INV-39](#inv-39-every-insert-fills-each-required-column) counts such a column as filled. `Compiler::insertFunction()` writes each retained attribute, read-only or not. `Compiler::updateFunction()` refuses a change through `ResolvedMap::readOnlyAttributes()`. The update trigger's create branch in `Compiler::updateDependent()` reads `ResolvedMap::assignmentsTo()` alone, because an unchanged read-only value there is NULL.

**Pinned by.** `CompilerTest::it_stores_a_read_only_mapped_attribute_on_an_insert_through_the_view_and_still_refuses_a_change_to_it()`, through an Eloquent `create()`. It covers a primary column, a `NOT NULL` column with a default, a `GENERATED ALWAYS` identity, a dependent column and a dependent's `NOT NULL` column that only a read-only attribute fills. It also pins the refusal of an update. `InstallerTest::it_installs_and_recompiles_a_map_that_exposes_a_generated_column_read_only()` pins a read-only generated column.
