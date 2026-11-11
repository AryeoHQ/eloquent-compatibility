<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

final class Pass
{
    public int $walked = 0;

    public int $changed = 0;

    public int $gone = 0;

    /** @var array<value-of<Outcome>, int> */
    public array $counts = [];

    /** @var array<value-of<Outcome>, array<string, int>> */
    public array $reasons = [];

    /** @var array<value-of<Outcome>, list<int|string>> */
    public array $blockedKeys = [];

    /** @var array<value-of<Outcome>, array<string, list<int|string>>> */
    public array $reasonKeys = [];

    public null|string $watermark = null;

    public function record(int|string $key, Outcome $outcome, null|Outcome $previous, null|string $reason): void
    {
        $this->counts[$outcome->value] = ($this->counts[$outcome->value] ?? 0) + 1;

        if ($outcome->changesSuccessors() || $previous !== $outcome) {
            $this->changed++;
        }

        if ($outcome->blocks()) {
            $this->blockedKeys[$outcome->value][] = $key;
            $reason ??= '';
            $this->reasons[$outcome->value][$reason] = ($this->reasons[$outcome->value][$reason] ?? 0) + 1;

            if (count($this->reasonKeys[$outcome->value][$reason] ?? []) < Ledger::FIRST_KEYS) {
                $this->reasonKeys[$outcome->value][$reason][] = $key;
            }
        }
    }

    public function count(Outcome $outcome): int
    {
        return $this->counts[$outcome->value] ?? 0;
    }

    public function blocked(): bool
    {
        return $this->count(Outcome::Refused) > 0 || $this->count(Outcome::Failed) > 0;
    }

    public function absorb(self $pass): void
    {
        $this->walked += $pass->walked;
        $this->changed += $pass->changed;
        $this->gone += $pass->gone;

        foreach ($pass->counts as $status => $count) {
            $this->counts[$status] = ($this->counts[$status] ?? 0) + $count;
        }

        foreach ($pass->blockedKeys as $status => $keys) {
            $this->blockedKeys[$status] = [...$this->blockedKeys[$status] ?? [], ...$keys];
        }

        foreach ($pass->reasons as $status => $reasons) {
            foreach ($reasons as $reason => $count) {
                $this->reasons[$status][$reason] = ($this->reasons[$status][$reason] ?? 0) + $count;
            }
        }

        foreach ($pass->reasonKeys as $status => $reasons) {
            foreach ($reasons as $reason => $keys) {
                $this->reasonKeys[$status][$reason] = array_slice(
                    array: [...$this->reasonKeys[$status][$reason] ?? [], ...$keys],
                    offset: 0,
                    length: Ledger::FIRST_KEYS,
                );
            }
        }
    }
}
