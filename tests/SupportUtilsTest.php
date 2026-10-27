<?php
declare(strict_types=1);

/**
 * 辅助工具类（A/B/C 档）测试
 * 运行：php8 tests/run.php SupportUtilsTest
 */

require_once __DIR__ . '/bootstrap.php';

use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;
use Mtphp\Support\Str;
use Mtphp\Support\Arr;
use Mtphp\Support\Xml;
use Mtphp\Support\Id;
use Mtphp\Support\Mask;
use Mtphp\Support\Tree;
use Mtphp\Support\Filesystem;
use Mtphp\Support\Date;
use Mtphp\Support\Csv;
use Mtphp\Support\Number;
use Mtphp\Support\Hash;
use Mtphp\Support\Encoding;
use Mtphp\Support\Ip;
use Mtphp\Support\Url;
use Mtphp\Support\Html;
use Mtphp\Support\RateLimiter;
use Mtphp\Support\Collection;
use Mtphp\Validation\Validator;
use Mtphp\Cache\CacheInterface;

class UtilsFakeCache implements CacheInterface
{
    private array $data = [];
    public function get(string $key, mixed $default = null): mixed { return $this->data[$key] ?? $default; }
    public function set(string $key, mixed $value, ?int $ttl = null): bool { $this->data[$key] = $value; return true; }
    public function has(string $key): bool { return array_key_exists($key, $this->data); }
    public function delete(string $key): bool { unset($this->data[$key]); return true; }
    public function clear(): bool { $this->data = []; return true; }
    public function remember(string $key, ?int $ttl, callable $callback): mixed { return $this->data[$key] ??= $callback(); }
    public function pull(string $key, mixed $default = null): mixed { $v = $this->get($key, $default); $this->delete($key); return $v; }
    public function add(string $key, mixed $value, ?int $ttl = null): bool { if ($this->has($key)) return false; $this->set($key, $value, $ttl); return true; }
    public function forever(string $key, mixed $value): bool { return $this->set($key, $value); }
    public function rememberForever(string $key, callable $callback): mixed { return $this->remember($key, null, $callback); }
    public function increment(string $key, int $step = 1): int { $this->data[$key] = ($this->data[$key] ?? 0) + $step; return $this->data[$key]; }
    public function decrement(string $key, int $step = 1): int { $this->data[$key] = ($this->data[$key] ?? 0) - $step; return $this->data[$key]; }
    public function lock(string $name, int $seconds = 0, ?string $owner = null): \Mtphp\Cache\Lock { return new \Mtphp\Cache\Lock($this, $name, $seconds, $owner); }
}

class SupportUtilsTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/mtphp_su_' . uniqid();
        Filesystem::ensureDir($this->tmp);
    }

    protected function tearDown(): void
    {
        Filesystem::delete($this->tmp);
    }

    // ----------------------------- Str -----------------------------
    public function testStrUuid(): void
    {
        $u = Str::uuid4();
        Assert::assertTrue(Str::isUuid($u));
        Assert::assertSame(36, strlen($u));
        $u7 = Str::uuid7();
        Assert::assertTrue(Str::isUuid($u7));
    }

    public function testStrUlid(): void
    {
        $u = Str::ulid();
        Assert::assertTrue(Str::isUlid($u));
        Assert::assertSame(26, strlen($u));
    }

    public function testStrRandom(): void
    {
        Assert::assertSame(20, strlen(Str::random(20)));
    }

    public function testStrCase(): void
    {
        Assert::assertSame('helloWorld', Str::camel('hello_world'));
        Assert::assertSame('hello_world', Str::snake('helloWorld'));
        Assert::assertSame('hello-world', Str::kebab('helloWorld'));
        Assert::assertSame('HelloWorld', Str::studly('hello_world'));
    }

    public function testStrContainsStartsEnds(): void
    {
        Assert::assertTrue(Str::contains('abcdef', 'cd'));
        Assert::assertFalse(Str::contains('abcdef', 'zz'));
        Assert::assertTrue(Str::startsWith('abcdef', 'ab'));
        Assert::assertTrue(Str::endsWith('abcdef', 'ef'));
    }

    public function testStrLimitAndReplace(): void
    {
        Assert::assertSame('12345...', Str::limit('1234567890', 5));
        Assert::assertSame('a-b/c', Str::replaceFirst('/', '-', 'a/b/c'));
        Assert::assertSame('a/b-c', Str::replaceLast('/', '-', 'a/b/c'));
        Assert::assertSame('a', Str::before('a/b/c', '/'));
        Assert::assertSame('b/c', Str::after('a/b/c', '/'));
    }

    public function testStrSlugAndMask(): void
    {
        Assert::assertSame('hello-world', Str::slug('Hello World!'));
        Assert::assertSame('138****1111', Str::mask('13800001111', 3, 4));
    }

    public function testStrJson(): void
    {
        Assert::assertTrue(Str::isJson('{"a":1}'));
        Assert::assertFalse(Str::isJson('not json'));
    }

    // ----------------------------- Arr -----------------------------
    public function testArrDotAndGet(): void
    {
        $data = ['a' => ['b' => 2]];
        Assert::assertSame(2, Arr::get($data, 'a.b'));
        Assert::assertSame('d', Arr::get($data, 'x.y', 'd'));
        Assert::assertSame(['a.b' => 2], Arr::dot($data));
        Assert::assertSame($data, Arr::undot(['a.b' => 2]));
    }

    public function testArrSetHasForgetOnlyExcept(): void
    {
        $a = Arr::set([], 'x.y', 1);
        Assert::assertTrue(Arr::has($a, 'x.y'));
        $a = Arr::forget($a, 'x.y');
        Assert::assertFalse(Arr::has($a, 'x.y'));
        Assert::assertSame(['a' => 1], Arr::only(['a' => 1, 'b' => 2], ['a']));
        Assert::assertSame(['b' => 2], Arr::except(['a' => 1, 'b' => 2], ['a']));
    }

    public function testArrOps(): void
    {
        [$keys, $vals] = Arr::divide(['a' => 1, 'b' => 2]);
        Assert::assertSame(['a', 'b'], $keys);
        Assert::assertSame([1, 2], $vals);
        Assert::assertSame([1, 2, 3], Arr::flatten([1, [2, [3]]]));
        $sorted = Arr::sortBy([['a' => 2], ['a' => 1]], 'a');
        Assert::assertSame(1, $sorted[0]['a']);
        Assert::assertSame([[1, 3], [1, 4], [2, 3], [2, 4]], Arr::crossJoin([1, 2], [3, 4]));
        $shuffled = Arr::shuffle([1, 2, 3], 1234);
        sort($shuffled);
        Assert::assertSame([1, 2, 3], $shuffled);
        Assert::assertSame(3, count($shuffled));
        Assert::assertSame([1, 3], Arr::pluck([['id' => 1], ['id' => 3]], 'id'));
        Assert::assertTrue(Arr::isAssoc(['x' => 1]));
        Assert::assertSame([1, 2, 3], Arr::wrap([1, 2, 3]));
        Assert::assertSame([1], Arr::wrap(1));
    }

    // ----------------------------- Xml -----------------------------
    public function testXmlRoundTrip(): void
    {
        $arr = ['user' => ['name' => '张三', 'age' => 18]];
        $xml = Xml::toXml($arr, 'root');
        $back = Xml::parse($xml);
        Assert::assertSame('张三', $back['user']['name']);
        Assert::assertSame('18', $back['user']['age']);
    }

    public function testXmlAttributes(): void
    {
        $xml = Xml::toXml(['item' => ['@attributes' => ['id' => 1], '@value' => 'x']], 'root');
        $back = Xml::parse($xml);
        Assert::assertSame('1', $back['item']['@attributes']['id']);
        Assert::assertSame('x', $back['item']['@value']);
    }

    public function testXmlRepeatedAndCdata(): void
    {
        $xml = Xml::toXml(['item' => [['id' => 1], ['id' => 2]]], 'root');
        $back = Xml::parse($xml);
        Assert::assertSame(2, count($back['item']));
        Assert::assertSame('1', $back['item'][0]['id']);

        $xml2 = Xml::toXml(['note' => ['@cdata' => '<b>x</b>']], 'root');
        $back2 = Xml::parse($xml2);
        Assert::assertSame('<b>x</b>', $back2['note']);
    }

    public function testXmlParseFile(): void
    {
        $path = $this->tmp . '/t.xml';
        Filesystem::put($path, Xml::toXml(['k' => 'v'], 'root'));
        $back = Xml::parseFile($path);
        Assert::assertSame('v', $back['k']);
    }

    // ----------------------------- Id -----------------------------
    public function testIdGenerators(): void
    {
        Assert::assertSame(10, strlen(Id::nanoid(10)));
        Assert::assertSame(8, strlen(Id::shortId(8)));
        $sf = Id::snowflake(1, 1);
        Assert::assertTrue(is_int($sf));
        Assert::assertTrue($sf > 0);
        Assert::assertTrue(Str::isUuid(Id::uuid4()));
        Assert::assertTrue(Str::isUlid(Id::ulid()));
    }

    public function testIdSerial(): void
    {
        $s = Id::serial('ORD-', 1);
        Assert::assertTrue(Str::startsWith($s, 'ORD-'));
        Assert::assertTrue(Str::endsWith($s, '-000001'));
        Assert::assertContains($s, date('Ymd'));
    }

    // ----------------------------- Mask -----------------------------
    public function testMask(): void
    {
        Assert::assertSame('138****1111', Mask::phone('13800001111'));
        Assert::assertSame('z*******@example.com', Mask::email('zhangsan@example.com'));
        Assert::assertSame('张*丰', Mask::name('张三丰'));
        Assert::assertSame('李*', Mask::name('李四'));
        Assert::assertSame('110101********1234', Mask::idCard('110101199001011234'));
        Assert::assertTrue(Str::endsWith(Mask::bankCard('6222021234567890'), '7890'));
    }

    // ----------------------------- Tree -----------------------------
    public function testTree(): void
    {
        $flat = [
            ['id' => 1, 'parent_id' => 0, 'name' => 'root'],
            ['id' => 2, 'parent_id' => 1, 'name' => 'c1'],
            ['id' => 3, 'parent_id' => 1, 'name' => 'c2'],
            ['id' => 4, 'parent_id' => 2, 'name' => 'g'],
        ];
        $tree = Tree::build($flat);
        Assert::assertSame(1, count($tree));
        Assert::assertSame(2, count($tree[0]['children']));
        Assert::assertSame(3, Tree::maxDepth($tree));
        Assert::assertSame(3, count(Tree::descendants($flat, 1)));
        Assert::assertSame(2, count(Tree::ancestors($flat, 4)));
        Assert::assertSame(4, count(Tree::flatten($tree)));
    }

    // ----------------------------- Filesystem -----------------------------
    public function testFilesystem(): void
    {
        Assert::assertSame('/var/www/file.txt', Filesystem::join('/var', 'www/', 'file.txt'));
        Assert::assertSame('a/b/c', Filesystem::join('a', 'b', 'c'));
        Assert::assertSame('a/b/', Filesystem::normalize('a//b/'));
        Assert::assertSame('image/jpeg', Filesystem::mimeFromExt('jpg'));
        Assert::assertSame('application/octet-stream', Filesystem::mimeFromExt('xyz'));
        Assert::assertSame('0 B', Filesystem::humanSize(0));
        Assert::assertContains(Filesystem::humanSize(1024), 'KB');

        $p = $this->tmp . '/sub/x.txt';
        Assert::assertTrue(Filesystem::put($p, 'hi'));
        Assert::assertSame('hi', Filesystem::get($p));
        Assert::assertTrue(is_file($p));
    }

    // ----------------------------- Date -----------------------------
    public function testDate(): void
    {
        Assert::assertSame('2020-01-01', Date::format(strtotime('2020-01-01 12:00:00'), 'Y-m-d'));
        Assert::assertSame('3分钟前', Date::humanize(time() - 200));
        Assert::assertSame('刚刚', Date::humanize(time() - 3));
        Assert::assertSame(strtotime('2020-01-01 00:00:00'), Date::startOfDay(strtotime('2020-01-01 15:00:00')));
        Assert::assertSame(strtotime('2020-01-01 23:59:59'), Date::endOfDay(strtotime('2020-01-01 15:00:00')));
        Assert::assertTrue(Date::between(strtotime('2020-01-15'), strtotime('2020-01-01'), strtotime('2020-02-01')));
        Assert::assertFalse(Date::between(strtotime('2019-01-15'), strtotime('2020-01-01'), strtotime('2020-02-01')));
        Assert::assertSame(10, Date::diffInDays(strtotime('2020-01-11'), strtotime('2020-01-01')));
    }

    // ----------------------------- Csv -----------------------------
    public function testCsv(): void
    {
        $rows = [['a' => 1, 'b' => 2], ['a' => 3, 'b' => 4]];
        $csv = Csv::toCsv($rows);
        $back = Csv::parse($csv);
        Assert::assertSame(2, count($back));
        Assert::assertSame('1', $back[0]['a']);

        $back2 = Csv::parse("a,b\n1,2");
        Assert::assertSame('1', $back2[0]['a']);
    }

    // ----------------------------- Number -----------------------------
    public function testNumber(): void
    {
        Assert::assertSame('1,234,567.89', Number::thousands(1234567.891));
        Assert::assertSame('¥1,234.50', Number::currency(1234.5));
        Assert::assertSame('12.34%', Number::percent(0.1234));
        Assert::assertSame(99, Number::safeDivide(10, 0, 99));
        Assert::assertEquals(5, Number::safeDivide(10, 2));
        Assert::assertSame('1.23万', Number::wan(12345));
        Assert::assertSame('1.23亿', Number::wan(123456789));
        Assert::assertSame('壹仟贰佰叁拾肆圆伍角陆分', Number::rmbUpperCase(1234.56));
        Assert::assertSame('壹佰圆零叁分', Number::rmbUpperCase(100.03));
        Assert::assertSame('贰佰圆整', Number::rmbUpperCase(200));
        Assert::assertSame('壹佰零伍圆整', Number::rmbUpperCase(105));
    }

    // ----------------------------- Hash -----------------------------
    public function testHash(): void
    {
        Assert::assertSame(hash_hmac('sha256', 'a', 'k'), Hash::hmac('a', 'k'));
        $h = Hash::bcrypt('secret');
        Assert::assertTrue(Hash::verify('secret', $h));
        Assert::assertFalse(Hash::verify('wrong', $h));
        Assert::assertSame(20, strlen(Hash::token(20)));

        $signed = Hash::sign(['uid' => 1], 'key', 60);
        $payload = Hash::verifySign($signed, 'key');
        Assert::assertNotNull($payload);
        Assert::assertSame(1, $payload['uid']);
        Assert::assertNull(Hash::verifySign($signed, 'wrong'));

        $q = Hash::signedQuery(['a' => 1], 'key');
        parse_str($q, $arr);
        $clean = Hash::verifySignedQuery($arr, 'key');
        Assert::assertNotNull($clean);
        Assert::assertSame('1', $clean['a']);
        Assert::assertNull(Hash::verifySignedQuery($arr, 'wrong'));
    }

    // ----------------------------- Encoding -----------------------------
    public function testEncoding(): void
    {
        $s = 'hello+/world=测试';
        Assert::assertSame($s, Encoding::base64UrlDecode(Encoding::base64UrlEncode($s)));
        Assert::assertSame('&lt;b&gt;', Encoding::htmlEntities('<b>'));
    }

    // ----------------------------- Ip -----------------------------
    public function testIp(): void
    {
        Assert::assertSame('1.2.3.4', Ip::realClientIp([
            'HTTP_X_FORWARDED_FOR' => '1.2.3.4, 5.6.7.8',
            'REMOTE_ADDR' => '9.9.9.9',
        ]));
        Assert::assertTrue(Ip::cidrMatch('10.0.0.5', '10.0.0.0/8'));
        Assert::assertFalse(Ip::cidrMatch('192.168.1.1', '10.0.0.0/8'));
        Assert::assertTrue(Ip::cidrMatch('192.168.1.1', '192.168.1.0/24'));
        Assert::assertTrue(Ip::isPrivate('192.168.1.1'));
        Assert::assertTrue(Ip::isPublic('8.8.8.8'));
        Assert::assertSame('8.8.8.8', Ip::fromLong(Ip::toLong('8.8.8.8')));
    }

    // ----------------------------- Url -----------------------------
    public function testUrl(): void
    {
        Assert::assertSame('a=1&b=2', Url::buildQuery(['a' => 1, 'b' => 2]));
        Assert::assertTrue(Url::isValid('https://x.com'));
        Assert::assertFalse(Url::isValid('not-a-url'));
        $cur = Url::current([
            'HTTPS' => 'on', 'HTTP_HOST' => 'example.com', 'REQUEST_URI' => '/p?x=1',
        ]);
        Assert::assertContains($cur, 'example.com');
        $parsed = Url::parse('https://x.com/a?b=1');
        Assert::assertSame('x.com', $parsed['host']);
    }

    // ----------------------------- Html -----------------------------
    public function testHtml(): void
    {
        Assert::assertSame('&lt;a&gt;', Html::escape('<a>'));
        Assert::assertSame('x', Html::stripTags('<b>x</b>'));
        Assert::assertSame('hello...', Html::excerpt('<p>hello world foo bar</p>', 5));
    }

    // ----------------------------- Macroable -----------------------------
    public function testMacro(): void
    {
        Str::macro('rev', fn (string $s) => strrev($s));
        Assert::assertSame('cba', Str::rev('abc'));

        $c = new Collection([1, 2, 3]);
        Collection::macro('sumTimes', function (Collection $self, int $n) {
            return $self->sum() * $n;
        });
        Assert::assertSame(12, $c->sumTimes(2));
    }

    // ----------------------------- Validator CN rules -----------------------------
    private function makeIdCard(string $p17): string
    {
        $w = [7, 9, 10, 5, 8, 4, 2, 1, 6, 3, 7, 9, 10, 5, 8, 4, 2];
        $c = ['1', '0', 'X', '9', '8', '7', '6', '5', '4', '3', '2'];
        $sum = 0;
        for ($i = 0; $i < 17; $i++) { $sum += (int) $p17[$i] * $w[$i]; }
        return $p17 . $c[$sum % 11];
    }

    private function makeCreditCode(string $p17): string
    {
        $chars = '0123456789ABCDEFGHJKLMNPQRTUWXY';
        $map = [];
        foreach (str_split($chars) as $i => $ch) { $map[$ch] = $i; }
        $w = [1, 3, 9, 27, 19, 26, 16, 17, 20, 29, 25, 13, 8, 24, 10, 30, 28];
        $sum = 0;
        for ($i = 0; $i < 17; $i++) { $sum += $map[$p17[$i]] * $w[$i]; }
        $mod = 31 - ($sum % 31);
        if ($mod === 31) { $mod = 0; }
        return $p17 . $chars[$mod];
    }

    public function testValidatorCn(): void
    {
        Assert::assertFalse(Validator::make(['m' => '13800001111'], ['m' => 'mobile_cn'])->fails());
        Assert::assertTrue(Validator::make(['m' => '12345'], ['m' => 'mobile_cn'])->fails());

        $id = $this->makeIdCard('11010119900307411');
        Assert::assertFalse(Validator::make(['id' => $id], ['id' => 'id_card'])->fails());
        Assert::assertTrue(Validator::make(['id' => substr($id, 0, 17) . '0'], ['id' => 'id_card'])->fails());

        $cc = $this->makeCreditCode('91110108MA01ABC23');
        Assert::assertFalse(Validator::make(['c' => $cc], ['c' => 'credit_code'])->fails());
        Assert::assertTrue(Validator::make(['c' => substr($cc, 0, 17) . '0'], ['c' => 'credit_code'])->fails());
    }

    // ----------------------------- RateLimiter -----------------------------
    public function testRateLimiter(): void
    {
        $rl = new RateLimiter(new UtilsFakeCache());
        Assert::assertSame(1, $rl->hit('k', 60));
        Assert::assertSame(2, $rl->hit('k', 60));
        Assert::assertTrue($rl->tooManyAttempts('k', 2, 60));
        Assert::assertFalse($rl->tooManyAttempts('k', 3, 60));
        Assert::assertFalse($rl->attempt('k', 2, 60, fn () => 'x')); // 已超限
        $rl->clear('k');
        Assert::assertSame('ok', $rl->attempt('k', 2, 60, fn () => 'ok'));
    }

    // ----------------------------- retry helper -----------------------------
    public function testRetry(): void
    {
        $x = 0;
        $r = retry(3, function () use (&$x) {
            $x++;
            if ($x < 3) { throw new \RuntimeException('e'); }
            return 'ok';
        });
        Assert::assertSame('ok', $r);
        Assert::assertSame(3, $x);

        Assert::assertException(function () {
            retry(2, function () { throw new \RuntimeException('x'); });
        }, \RuntimeException::class);
    }
}
