<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

final class Pass
{
    public int $walked = 0;

    public int $changed = 0;

    /** @var array<value-of<Outcome>, int> */
    public array $counts = [];

    /** @var array<value-of<Outcome>, array<string, int>> */
    public array $reasons = [];

    /** @var array<value-of<Outcome>, list<int|string>> */
    public array $blockedKeys = [];

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
    }
}
