<?php
declare(strict_types=1);

use Mtphp\Http\Request;
use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;
use Mtphp\Validation\ValidationException;

require_once __DIR__ . '/bootstrap.php';

/**
 * Request::validate() / all() 集成测试
 */
class RequestValidateTest extends TestCase
{
    private function make(array $post = [], array $query = [], string $body = '', string $ct = ''): Request
    {
        $r = new Request();
        $set = static function (string $prop, $val) use ($r): void {
            $p = new \ReflectionProperty(Request::class, $prop);
            $p->setValue($r, $val);
        };
        $set('query', $query);
        $set('post', $post);
        $set('body', $body === '' ? null : $body);
        $set('headers', $ct === '' ? [] : ['content-type' => $ct]);
        return $r;
    }

    public function testValidateReturnsValidatedSubset(): void
    {
        $r = $this->make(['name' => 'Tom', 'age' => '20', 'junk' => 'x']);
        $data = $r->validate(['name' => 'required', 'age' => 'required|integer']);
        Assert::assertSame('Tom', $data['name']);
        Assert::assertSame('20', $data['age']);
        Assert::assertFalse(array_key_exists('junk', $data), '校验返回应仅含白名单字段');
    }

    public function testValidateThrowsOnFailure(): void
    {
        $r = $this->make(['email' => 'not-an-email']);
        $threw = null;
        try {
            $r->validate(['email' => 'required|email']);
        } catch (ValidationException $e) {
            $threw = $e;
        }
        Assert::assertNotNull($threw, '校验失败应抛出 ValidationException');
        Assert::assertContains('email', implode(',', array_keys($threw->errors())));
    }

    public function testAllMergesQueryPostAndJson(): void
    {
        $r = $this->make(
            ['a' => 'post'],
            ['b' => 'query'],
            '{"c":"json"}',
            'application/json'
        );
        $all = $r->all();
        Assert::assertSame('post', $all['a']);
        Assert::assertSame('query', $all['b']);
        Assert::assertSame('json', $all['c']);
    }
}
