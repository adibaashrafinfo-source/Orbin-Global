<?php

namespace Tests\Unit;

use App\Exceptions\ApiException;
use App\Support\Allocator;
use PHPUnit\Framework\TestCase;

/** BR-062 on paper: amounts are paisa, documents arrive oldest first. */
class AllocatorTest extends TestCase
{
    /** @return array<int, array{table: string, id: int, open: int}> */
    private function docs(int ...$open): array
    {
        return array_map(fn ($o, $i) => ['table' => 'sales', 'id' => $i + 1, 'open' => $o], $open, array_keys($open));
    }

    public function test_the_oldest_document_is_settled_first_and_the_next_one_takes_the_rest(): void
    {
        $r = Allocator::oldestFirst($this->docs(100000, 100000, 100000), 250000);
        $this->assertSame([['table' => 'sales', 'id' => 1, 'amount' => 100000], ['table' => 'sales', 'id' => 2, 'amount' => 100000], ['table' => 'sales', 'id' => 3, 'amount' => 50000]], $r['allocations']);
        $this->assertSame(0, $r['remainder']);
    }

    public function test_money_above_everything_due_is_the_remainder(): void
    {
        $r = Allocator::oldestFirst($this->docs(30000, 20000), 75000);
        $this->assertSame([30000, 20000], array_column($r['allocations'], 'amount'));
        $this->assertSame(25000, $r['remainder']);
    }

    public function test_nothing_due_leaves_the_whole_amount_as_the_remainder(): void
    {
        $this->assertSame(['allocations' => [], 'remainder' => 500], Allocator::oldestFirst([], 500));
        $this->assertSame(['allocations' => [], 'remainder' => 500], Allocator::oldestFirst($this->docs(0, 0), 500));
    }

    public function test_one_paisa_short_leaves_one_paisa_open(): void
    {
        $r = Allocator::oldestFirst($this->docs(1000), 999);
        $this->assertSame([999], array_column($r['allocations'], 'amount'));
        $this->assertSame(0, $r['remainder']);
    }

    public function test_a_manual_split_is_kept_and_the_unspent_part_is_the_remainder(): void
    {
        $r = Allocator::manual($this->docs(1000, 2000), [['table' => 'sales', 'id' => 2, 'amount' => 1500]], 2000);
        $this->assertSame([['table' => 'sales', 'id' => 2, 'amount' => 1500]], $r['allocations']);
        $this->assertSame(500, $r['remainder']);
    }

    public function test_two_lines_for_one_document_are_added_before_they_are_checked(): void
    {
        $r = Allocator::manual($this->docs(1000), [['table' => 'sales', 'id' => 1, 'amount' => 600], ['table' => 'sales', 'id' => 1, 'amount' => 400]], 1000);
        $this->assertSame([['table' => 'sales', 'id' => 1, 'amount' => 1000]], $r['allocations']);
        $this->expectException(ApiException::class);
        Allocator::manual($this->docs(1000), [['table' => 'sales', 'id' => 1, 'amount' => 600], ['table' => 'sales', 'id' => 1, 'amount' => 401]], 2000);
    }

    public function test_a_manual_line_may_not_exceed_what_its_document_owes_or_name_a_document_that_is_not_open(): void
    {
        foreach ([[1, 1001], [9, 10]] as [$id, $amount]) {
            try {
                Allocator::manual($this->docs(1000), [['table' => 'sales', 'id' => $id, 'amount' => $amount]], 5000);
                $this->fail('expected ALLOCATION_EXCEEDS_DUE');
            } catch (ApiException $e) {
                $this->assertSame('ALLOCATION_EXCEEDS_DUE', $e->errorCode);
            }
        }
        try {
            Allocator::manual([['table' => 'goods_receipts', 'id' => 1, 'open' => 1000]], [['table' => 'sales', 'id' => 1, 'amount' => 10]], 100);
            $this->fail('a sale id is not a goods receipt id');
        } catch (ApiException $e) {
            $this->assertSame('ALLOCATION_EXCEEDS_DUE', $e->errorCode);
        }
    }

    public function test_the_split_may_not_exceed_the_amount_paid_and_zero_lines_are_ignored(): void
    {
        try {
            Allocator::manual($this->docs(1000, 1000), [['table' => 'sales', 'id' => 1, 'amount' => 600], ['table' => 'sales', 'id' => 2, 'amount' => 600]], 1000);
            $this->fail('expected ALLOCATION_EXCEEDS_AMOUNT');
        } catch (ApiException $e) {
            $this->assertSame('ALLOCATION_EXCEEDS_AMOUNT', $e->errorCode);
        }
        $r = Allocator::manual($this->docs(1000), [['table' => 'sales', 'id' => 1, 'amount' => 0]], 700);
        $this->assertSame([], $r['allocations']);
        $this->assertSame(700, $r['remainder']);
    }
}
