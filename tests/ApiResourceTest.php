<?php
declare(strict_types=1);

/**
 * API Resource 测试（零依赖）
 * 运行：php8 tests/run.php ApiResourceTest
 */

require_once __DIR__ . '/bootstrap.php';

use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;
use Mtphp\Database\DB;
use Mtphp\Database\Schema\Schema;
use Mtphp\Database\Schema\Blueprint;
use Mtphp\Database\Model;
use Mtphp\Http\Resources\JsonResource;
use Mtphp\Pagination\Paginator;
use function Tests\sqliteResolver;
use Tests\SqliteConnector;

class ApiUser extends Model
{
    protected static ?string $table = 'api_users';
    protected static string $primaryKey = 'id';
}

class UserResource extends JsonResource
{
    public function toArray($item): array
    {
        return [
            'id'   => $item['id'],
            'name' => $item['name'],
            // 故意裁剪掉 password 等内部字段
        ];
    }
}

class ApiResourceTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        DB::setResolver(sqliteResolver($this->pdo));

        Schema::create('api_users', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->string('password');
        });

        ApiUser::create(['name' => 'alice', 'password' => 'secret1']);
        ApiUser::create(['name' => 'bob', 'password' => 'secret2']);
    }

    protected function tearDown(): void
    {
        DB::setResolver(static fn () => new SqliteConnector(new \PDO('sqlite::memory:')));
    }

    public function testSingleResolve(): void
    {
        $user = ApiUser::find(1);
        $out  = (new UserResource($user))->resolve();

        Assert::assertSame(1, $out['id']);
        Assert::assertSame('alice', $out['name']);
        Assert::assertTrue(!array_key_exists('password', $out));
    }

    public function testCollectionResolve(): void
    {
        $users = ApiUser::all();
        $out   = UserResource::collection($users)->resolve();

        Assert::assertSame(2, count($out));
        Assert::assertSame('alice', $out[0]['name']);
        Assert::assertSame('bob', $out[1]['name']);
        Assert::assertTrue(!array_key_exists('password', $out[0]));
    }

    public function testPaginatorResolve(): void
    {
        $page = ApiUser::query()->paginate(1, 1);
        $out  = UserResource::collection($page)->resolve();

        Assert::assertTrue(array_key_exists('data', $out));
        Assert::assertTrue(array_key_exists('meta', $out));
        Assert::assertSame(1, count($out['data']));
        Assert::assertSame(2, $out['meta']['total']);
        Assert::assertSame(2, $out['meta']['last_page']);
        Assert::assertTrue($out['meta']['has_more']);
    }

    public function testResponse(): void
    {
        $user = ApiUser::find(1);
        $resp = (new UserResource($user))->response();
        Assert::assertSame(200, $resp->getStatus());
        $body = (string) $resp->getBody();
        Assert::assertTrue(str_contains($body, '"name":"alice"'));
        Assert::assertTrue(!str_contains($body, 'secret1'));
    }
}
