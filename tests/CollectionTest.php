<?php
declare(strict_types=1);

/**
 * 集合 Collection 测试
 * 运行：php8 tests/run.php CollectionTest
 */

require_once __DIR__ . '/bootstrap.php';

use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;
use Mtphp\Support\Collection;

class CollectionTest extends TestCase
{
    public function testMapFilterReduce(): void
    {
        $c = collect([1, 2, 3, 4]);
        $out = $c->map(fn ($x) => $x * 2)->filter(fn ($x) => $x > 4)->toArray();
        Assert::assertSame([6, 8], $out);

        $sum = collect([1, 2, 3])->reduce(fn ($a, $b) => $a + $b, 0);
        Assert::assertSame(6, $sum);
    }

    public function testPluckAndImplode(): void
    {
        $users = [
            ['id' => 1, 'name' => 'a'],
            ['id' => 2, 'name' => 'b'],
        ];
        Assert::assertSame('1,2', collect($users)->pluck('id')->implode(','));
        Assert::assertSame('a-b', collect($users)->implode('-', 'name'));
    }

    public function testSumAvgMaxMin(): void
    {
        $c = collect([10, 20, 30]);
        Assert::assertSame(60, $c->sum());
        Assert::assertSame(20, $c->avg());
        Assert::assertSame(30, $c->max());
        Assert::assertSame(10, $c->min());

        $orders = [['amount' => 5], ['amount' => 15]];
        Assert::assertSame(20, collect($orders)->sum('amount'));
    }

    public function testFirstLastContains(): void
    {
        $c = collect([3, 1, 4, 1, 5]);
        Assert::assertSame(3, $c->first());
        Assert::assertSame(5, $c->last());
        Assert::assertTrue($c->contains(4));
        Assert::assertFalse($c->contains(9));
        Assert::assertTrue($c->contains(fn ($x) => $x > 4));
    }

    public function testSortAndUnique(): void
    {
        $c = collect([3, 1, 2, 2, 3])->unique()->sort()->toArray();
        Assert::assertSame([1, 2, 3], $c);

        $users = [['age' => 30], ['age' => 10], ['age' => 20]];
        $ages = collect($users)->sortBy('age')->pluck('age')->toArray();
        Assert::assertSame([10, 20, 30], $ages);
    }

    public function testGroupBy(): void
    {
        $rows = [
            ['type' => 'a', 'v' => 1],
            ['type' => 'b', 'v' => 2],
            ['type' => 'a', 'v' => 3],
        ];
        $g = collect($rows)->groupBy('type');
        Assert::assertSame(2, count($g['a']));
        Assert::assertSame(1, count($g['b']));
    }

    public function testCountableAndArrayAccess(): void
    {
        $c = new Collection([1, 2, 3]);
        Assert::assertSame(3, $c->count());
        Assert::assertTrue($c->isNotEmpty());
        Assert::assertSame(2, $c[1]);
        Assert::assertTrue(isset($c[0]));
    }

    public function testKeyBy(): void
    {
        $users = [
            ['id' => 1, 'name' => 'a'],
            ['id' => 2, 'name' => 'b'],
        ];
        $byId = collect($users)->keyBy('id')->toArray();
        Assert::assertSame('a', $byId[1]['name']);
        Assert::assertSame('b', $byId[2]['name']);

        // 回调形式
        $byName = collect($users)->keyBy(fn ($u) => $u['name'])->toArray();
        Assert::assertSame(1, $byName['a']['id']);
    }

    public function testWhere(): void
    {
        $rows = [
            ['id' => 1, 'age' => 10, 'active' => 1],
            ['id' => 2, 'age' => 30, 'active' => 0],
            ['id' => 3, 'age' => 20, 'active' => 1],
        ];
        // key/value 默认 ==
        Assert::assertSame(2, collect($rows)->where('active', 1)->count());
        // 操作符形式
        Assert::assertSame(2, collect($rows)->where('age', '>', 15)->count());
        // 回调形式
        Assert::assertSame(1, collect($rows)->where(fn ($r) => $r['age'] === 30)->count());
    }

    public function testWhereIn(): void
    {
        $rows = [
            ['id' => 1], ['id' => 2], ['id' => 3],
        ];
        Assert::assertSame(2, collect($rows)->whereIn('id', [1, 3])->count());
    }

    public function testFirstWhere(): void
    {
        $rows = [
            ['id' => 1, 'name' => 'a'],
            ['id' => 2, 'name' => 'b'],
        ];
        Assert::assertSame('b', collect($rows)->firstWhere('id', 2)['name']);
        Assert::assertNull(collect($rows)->firstWhere('id', 99));
        Assert::assertSame('a', collect($rows)->firstWhere(fn ($r) => $r['id'] === 1)['name']);
    }

    public function testOnlyAndExcept(): void
    {
        $c = new Collection(['a' => 1, 'b' => 2, 'c' => 3], true);
        Assert::assertSame(['a' => 1, 'c' => 3], $c->only(['a', 'c'])->toArray());
        Assert::assertSame(['b' => 2], $c->except(['a', 'c'])->toArray());
    }

    public function testPartition(): void
    {
        [$pass, $fail] = collect([1, 2, 3, 4, 5])->partition(fn ($n) => $n > 3);
        Assert::assertSame([4, 5], $pass->values()->toArray());
        Assert::assertSame([1, 2, 3], $fail->values()->toArray());
    }

    public function testZip(): void
    {
        $z = collect([1, 2, 3])->zip([4, 5, 6]);
        Assert::assertSame([[1, 4], [2, 5], [3, 6]], $z->toArray());

        // 多余数组不足补 null
        $z2 = collect([1, 2, 3])->zip([4, 5]);
        Assert::assertSame([[1, 4], [2, 5], [3, null]], $z2->toArray());
    }

    public function testCollapse(): void
    {
        $flat = collect([[1, 2], [3], [4, 5]])->collapse();
        Assert::assertSame([1, 2, 3, 4, 5], $flat->toArray());
    }
}
