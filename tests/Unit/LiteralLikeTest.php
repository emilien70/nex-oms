<?php

namespace Tests\Unit;

use App\Support\LiteralLike;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\SQLiteConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class LiteralLikeTest extends TestCase
{
    #[DataProvider('drivers')]
    public function test_literal_search_compiles_portable_sql_with_bound_values(string $driver, string $quote): void
    {
        $pdo = fn () => throw new RuntimeException('SQL compilation must not connect to a database.');
        $connection = $driver === 'mysql' ? new MySqlConnection($pdo) : new SQLiteConnection($pdo);
        $query = new Builder($connection->table('orders'));

        LiteralLike::where($query, 'orders.customer_login', LiteralLike::contains("m_tomek%\\!'"));

        $this->assertSame(
            'select * from '.$quote.'orders'.$quote.' where '.$quote.'orders'.$quote.'.'.$quote.'customer_login'.$quote." LIKE ? ESCAPE '!'",
            $query->toSql(),
        );
        $this->assertSame(["%m!_tomek!%\\!!'%"], $query->getBindings());
    }

    public static function drivers(): array
    {
        return ['sqlite' => ['sqlite', '"'], 'mysql' => ['mysql', '`']];
    }
}
