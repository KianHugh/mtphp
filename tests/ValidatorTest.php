<?php
declare(strict_types=1);

/**
 * Validator 校验组件测试
 * 运行：php8 tests/run.php ValidatorTest
 */

require_once __DIR__ . '/bootstrap.php';

use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;
use Mtphp\Validation\Validator;
use Mtphp\Validation\ValidationException;
use Mtphp\Database\DB;
use Mtphp\Database\Schema\Schema;
use Mtphp\Database\Schema\Blueprint;
use function Tests\sqliteResolver;
use Tests\SqliteConnector;

class ValidatorTest extends TestCase
{
    public function testRequiredAndEmail(): void
    {
        $v = Validator::make(
            ['name' => '', 'email' => 'not-an-email'],
            ['name' => 'required', 'email' => 'required|email']
        );
        Assert::assertTrue($v->fails());
        Assert::assertTrue(isset($v->errors()['name']));
        Assert::assertTrue(isset($v->errors()['email']));

        $ok = Validator::make(
            ['name' => '张三', 'email' => 'a@b.com'],
            ['name' => 'required', 'email' => 'required|email']
        );
        Assert::assertTrue($ok->validate());
        Assert::assertSame('张三', $ok->validated()['name']);
    }

    public function testNumericMinMaxInteger(): void
    {
        $v = Validator::make(
            ['age' => 'abc'],
            ['age' => 'integer|min:0|max:150']
        );
        Assert::assertTrue($v->fails());

        $v2 = Validator::make(
            ['age' => 200],
            ['age' => 'integer|min:0|max:150']
        );
        Assert::assertTrue($v2->fails());

        $v3 = Validator::make(
            ['age' => 30],
            ['age' => 'integer|min:0|max:150']
        );
        Assert::assertTrue($v3->validate());
    }

    public function testInRule(): void
    {
        $v = Validator::make(['s' => 'x'], ['s' => 'in:0,1,2']);
        Assert::assertTrue($v->fails());

        $v2 = Validator::make(['s' => '1'], ['s' => 'in:0,1,2']);
        Assert::assertTrue($v2->validate());
    }

    public function testNullableSkipsOthers(): void
       {
        // age 为空且 nullable：其余规则应跳过，视为通过
        $v = Validator::make(['age' => ''], ['age' => 'nullable|integer|min:0']);
        Assert::assertTrue($v->validate());

        // 非空时仍受其余规则约束
        $v2 = Validator::make(['age' => 'x'], ['age' => 'nullable|integer']);
        Assert::assertTrue($v2->fails());
    }

    public function testConfirmedAndSame(): void
    {
        $v = Validator::make(
            ['password' => 'secret', 'password_confirmation' => 'other'],
            ['password' => 'required|confirmed']
        );
        Assert::assertTrue($v->fails());

        $v2 = Validator::make(
            ['password' => 'secret', 'password_confirmation' => 'secret'],
            ['password' => 'required|confirmed']
        );
        Assert::assertTrue($v2->validate());

        $v3 = Validator::make(
            ['a' => 'x', 'b' => 'y'],
            ['a' => 'same:b']
        );
        Assert::assertTrue($v3->fails());
    }

    public function testCustomExtension(): void
    {
        Validator::extend('even', fn ($value) => is_numeric($value) && (int) $value % 2 === 0);
        $v = Validator::make(['n' => 3], ['n' => 'even']);
        Assert::assertTrue($v->fails());
        $v2 = Validator::make(['n' => 4], ['n' => 'even']);
        Assert::assertTrue($v2->validate());
    }

    public function testHelperThrowsOnFailure(): void
    {
        Assert::assertException(function () {
            validate(['email' => 'bad'], ['email' => 'required|email']);
        }, ValidationException::class);
    }

    public function testNewRuleset(): void
    {
        // boolean
        Assert::assertTrue(Validator::make(['b' => 1], ['b' => 'boolean'])->validate());
        Assert::assertTrue(Validator::make(['b' => 'no'], ['b' => 'boolean'])->fails());

        // digits / digits_between
        Assert::assertTrue(Validator::make(['c' => '1234'], ['c' => 'digits:4'])->validate());
        Assert::assertTrue(Validator::make(['c' => '123'], ['c' => 'digits:4'])->fails());
        Assert::assertTrue(Validator::make(['c' => '12345'], ['c' => 'digits_between:3,5'])->validate());

        // array
        Assert::assertTrue(Validator::make(['a' => [1, 2]], ['a' => 'array'])->validate());
        Assert::assertTrue(Validator::make(['a' => 'x'], ['a' => 'array'])->fails());

        // json
        Assert::assertTrue(Validator::make(['j' => '{"k":1}'], ['j' => 'json'])->validate());
        Assert::assertTrue(Validator::make(['j' => '{bad}'], ['j' => 'json'])->fails());

        // starts_with / ends_with
        Assert::assertTrue(Validator::make(['s' => 'https://x'], ['s' => 'starts_with:https'])->validate());
        Assert::assertTrue(Validator::make(['s' => 'a.png'], ['s' => 'ends_with:.png,.jpg'])->validate());

        // different
        Assert::assertTrue(Validator::make(['p' => 'a', 'q' => 'b'], ['p' => 'different:q'])->validate());
        Assert::assertTrue(Validator::make(['p' => 'a', 'q' => 'a'], ['p' => 'different:q'])->fails());

        // size（字符串长度 / 数值相等）
        Assert::assertTrue(Validator::make(['s' => 'abcd'], ['s' => 'size:4'])->validate());
        Assert::assertTrue(Validator::make(['n' => 5], ['n' => 'size:5'])->validate());
        Assert::assertTrue(Validator::make(['n' => 6], ['n' => 'size:5'])->fails());

        // accepted
        Assert::assertTrue(Validator::make(['agree' => 'yes'], ['agree' => 'accepted'])->validate());
        Assert::assertTrue(Validator::make(['agree' => '0'], ['agree' => 'accepted'])->fails());

        // before / after（日期字符串）
        Assert::assertTrue(Validator::make(['d' => '2020-01-01'], ['d' => 'before:2021-01-01'])->validate());
        Assert::assertTrue(Validator::make(['d' => '2022-01-01'], ['d' => 'before:2021-01-01'])->fails());
        Assert::assertTrue(Validator::make(['d' => '2022-01-01'], ['d' => 'after:2021-01-01'])->validate());
    }

    public function testExistsRule(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        DB::setResolver(sqliteResolver($pdo));

        Schema::create('val_users', function (Blueprint $t): void {
            $t->id();
            $t->string('email');
        });
        DB::table('val_users')->insert(['email' => 'a@b.com']);

        // 存在的邮箱通过
        Assert::assertTrue(Validator::make(['email' => 'a@b.com'], ['email' => 'exists:val_users,email'])->validate());

        // 不存在的邮箱失败
        Assert::assertTrue(Validator::make(['email' => 'no@such.com'], ['email' => 'exists:val_users,email'])->fails());

        // 列缺省取字段名（email）
        Assert::assertTrue(Validator::make(['email' => 'a@b.com'], ['email' => 'exists:val_users'])->validate());

        // 空值不参与校验（可选字段留空放行）
        Assert::assertTrue(Validator::make(['email' => ''], ['email' => 'exists:val_users,email'])->validate());

        // 唯一规则（反向）仍可用
        Assert::assertTrue(Validator::make(['email' => 'a@b.com'], ['email' => 'unique:val_users,email'])->fails());
        Assert::assertTrue(Validator::make(['email' => 'fresh@x.com'], ['email' => 'unique:val_users,email'])->validate());

        DB::setResolver(static fn () => new SqliteConnector(new \PDO('sqlite::memory:')));
    }
}
