<?php
declare(strict_types=1);

/**
 * 分页器测试（内存 SQLite，零外部依赖）
 * 运行：php8 tests/run.php PaginationTest
 */

require_once __DIR__ . '/bootstrap.php';

use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;
use Mtphp\Database\DB;
use Mtphp\Database\Schema\Schema;
use Mtphp\Database\Schema\Blueprint;
use Mtphp\Database\Model;
use Mtphp\Database\Query;
use Mtphp\Pagination\Paginator;
use function Tests\sqliteResolver;
use Tests\SqliteConnector;

class PagUser extends Model
{
    protected static ?string $table = 'pag_users';
    protected static string $primaryKey = 'id';
}

class PaginationTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        DB::setResolver(sqliteResolver($this->pdo));

        Schema::create('pag_users', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
        });

        for ($i = 1; $i <= 25; $i++) {
            PagUser::create(['name' => 'u' . $i]);
        }
    }

    protected function tearDown(): void
    {
        DB::setResolver(static fn () => new SqliteConnector(new \PDO('sqlite::memory:')));
    }

    public function testQueryPaginate(): void
    {
        $page = DB::table('pag_users')->paginate(10, 1);
        Assert::assertTrue($page instanceof Paginator);
        Assert::assertSame(25, $page->total());
        Assert::assertSame(10, $page->perPage());
        Assert::assertSame(1, $page->currentPage());
        Assert::assertSame(3, $page->lastPage());
        Assert::assertSame(10, count($page->items()));
        Assert::assertTrue($page->hasMorePages());
        Assert::assertTrue($page->onFirstPage());
        Assert::assertNull($page->previousPageUrl());
        Assert::assertNotNull($page->nextPageUrl());
    }

    public function testQueryPaginateMiddlePage(): void
    {
        $page = DB::table('pag_users')->paginate(10, 2);
        Assert::assertSame(2, $page->currentPage());
        Assert::assertSame(10, count($page->items()));
        Assert::assertNotNull($page->previousPageUrl());
        Assert::assertNotNull($page->nextPageUrl());
    }

    public function testQueryPaginateLastPage(): void
    {
        $page = DB::table('pag_users')->paginate(10, 3);
        Assert::assertSame(3, $page->currentPage());
        Assert::assertSame(5, count($page->items()));
        Assert::assertFalse($page->hasMorePages());
        Assert::assertNull($page->nextPageUrl());
    }

    public function testModelPaginate(): void
    {
        $page = PagUser::query()->paginate(10, 1);
        Assert::assertSame(25, $page->total());
        Assert::assertSame(10, count($page->items()));
    }

    public function testPaginatorToArray(): void
    {
        $page  = DB::table('pag_users')->paginate(10, 1);
        $array = $page->toArray();
        Assert::assertSame(25, $array['total']);
        Assert::assertSame(10, $array['per_page']);
        Assert::assertSame(3, $array['last_page']);
        Assert::assertTrue($array['has_more']);
        Assert::assertSame(10, count($array['data']));
    }

    public function testPaginatorLinks(): void
    {
        $page = DB::table('pag_users')->paginate(10, 1)->setPath('/users');
        $html = $page->links();
        Assert::assertTrue(str_contains($html, '/users?page=2'));
        Assert::assertTrue(str_contains($html, '上一页'));
    }

    public function testQuerySimplePaginate(): void
    {
        $page = DB::table('pag_users')->simplePaginate(10, 1);
        Assert::assertTrue($page instanceof Paginator);
        Assert::assertSame(10, count($page->items()));
        Assert::assertTrue($page->hasMorePages());
        Assert::assertTrue($page->onFirstPage());
        Assert::assertNull($page->previousPageUrl());
        Assert::assertNotNull($page->nextPageUrl());
        // 简单分页：无总数
        Assert::assertSame(-1, $page->total());
        Assert::assertSame(-1, $page->lastPage());
    }

    public function testQuerySimplePaginateLastPage(): void
    {
        // 25 条，perPage=10 → 第 3 页仅 5 条，无下一页
        $page = DB::table('pag_users')->simplePaginate(10, 3);
        Assert::assertSame(5, count($page->items()));
        Assert::assertFalse($page->hasMorePages());
        Assert::assertNull($page->nextPageUrl());
        Assert::assertNotNull($page->previousPageUrl());
    }

    public function testModelSimplePaginate(): void
    {
        $page = PagUser::query()->simplePaginate(10, 2);
        Assert::assertSame(10, count($page->items()));
        Assert::assertTrue($page->hasMorePages());
    }

    public function testSimplePaginateToArrayAndLinks(): void
    {
        $page  = DB::table('pag_users')->simplePaginate(10, 1)->setPath('/users');
        $array = $page->toArray();
        Assert::assertSame(-1, $array['total']);
        Assert::assertSame(-1, $array['last_page']);
        Assert::assertTrue($array['has_more']);
        Assert::assertSame(10, count($array['data']));

        $html = $page->links();
        Assert::assertTrue(str_contains($html, '/users?page=2'));   // 下一页存在
        Assert::assertTrue(str_contains($html, '下一页'));
        Assert::assertFalse(str_contains($html, '第 '));             // 简单分页不显示页码
    }
}
