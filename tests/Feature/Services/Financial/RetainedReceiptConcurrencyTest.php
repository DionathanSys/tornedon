<?php

namespace Tests\Feature\Services\Financial;

use App\Models\AccountPayable;
use App\Models\AccountReceivable;
use App\Models\BankReconciliationGroup;
use App\Models\BankStatementImport;
use App\Models\CashMovement;
use App\Models\ChartAccount;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\User;
use App\Services\Financial\BankStatement\ReconcileReceiptWithRetainedExpensesService;
use App\Support\Financial\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Opt-in: uses an isolated MySQL/MariaDB *_tests database, never the in-memory SQLite suite. */
class RetainedReceiptConcurrencyTest extends TestCase
{
    private static bool $migrated = false;

    public static function races(): array
    {
        return ['same idempotency key' => ['same_key'], 'same bank line' => ['same_line'], 'same installment on different deposits' => ['same_title']];
    }

    #[DataProvider('races')]
    public function test_parallel_confirmations_cannot_duplicate_financial_effects(string $race): void
    {
        if (! getenv('RUN_MYSQL_CONCURRENCY_TESTS') || ! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requer RUN_MYSQL_CONCURRENCY_TESTS=1, MySQL/MariaDB e pcntl.');
        }
        $this->assertStringEndsWith('_tests', DB::connection()->getDatabaseName());
        if (! self::$migrated) {
            $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
            self::$migrated = true;
        }
        $user = User::factory()->create();
        $company = Company::factory()->create(['created_by' => $user->id]);
        $user->companies()->syncWithoutDetaching([$company->id => ['role' => 'owner', 'is_active' => true]]);
        $this->actingAs($user);
        $bank = FinancialAccount::create(['company_id' => $company->id, 'name' => 'Concorrência', 'type' => 'bank', 'is_active' => true, 'opening_balance' => 0]);
        $chart = ChartAccount::create(['company_id' => $company->id, 'code' => '2', 'name' => 'Taxas', 'type' => 'expense', 'is_active' => true, 'is_postable' => true]);
        $category = FinancialCategory::create(['company_id' => $company->id, 'name' => 'Taxas', 'chart_account_id' => $chart->id, 'allow_payable' => true, 'is_active' => true]);
        $document = AccountReceivable::create(['company_id' => $company->id, 'manual_counterparty_name' => 'Cliente', 'due_date' => '2026-11-10', 'due_amount' => '4600.00', 'paid_amount' => 0, 'paid' => false, 'status' => 'pending']);
        $title = $document->installments()->create(['company_id' => $company->id, 'sequence_number' => '01', 'due_date' => '2026-11-10', 'original_amount' => '4600.00', 'due_amount' => '4600.00', 'received_amount' => 0, 'balance_amount' => '4600.00', 'status' => 'pending']);
        $import = BankStatementImport::create(['company_id' => $company->id, 'financial_account_id' => $bank->id, 'status' => 'completed']);
        $line = $import->lines()->create(['company_id' => $company->id, 'financial_account_id' => $bank->id, 'amount' => '4500.00', 'description' => 'Concorrência', 'transaction_date' => '2026-11-15', 'transaction_key' => (string) Str::uuid(), 'metadata' => ['direction' => 'inflow'], 'reconciliation_status' => 'pending']);
        $otherLine = $race === 'same_title' ? $import->lines()->create([...$line->only(['company_id', 'financial_account_id', 'amount', 'description', 'transaction_date', 'metadata', 'reconciliation_status']), 'transaction_key' => (string) Str::uuid()]) : $line;
        $key = (string) Str::uuid();
        $jobs = [[$line->id, $key], [$otherLine->id, $race === 'same_key' ? $key : (string) Str::uuid()]];
        $sockets = [stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP), stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP)];
        DB::purge(); // Never share a PDO socket across forked processes.
        $children = [];
        foreach ($jobs as $index => [$lineId, $requestKey]) {
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid);
            if ($pid === 0) {
                foreach ($sockets as $number => $pair) {
                    fclose($pair[0]);
                    if ($number !== $index) {
                        fclose($pair[1]);
                    }
                }
                fread($sockets[$index][1], 1);
                try {
                    DB::purge();
                    auth()->setUser(User::findOrFail($user->id));
                    $group = app(ReconcileReceiptWithRetainedExpensesService::class)->reconcile($company->id, [$lineId], [['id' => $title->id, 'amount' => '4600.00', 'expenses' => [['amount' => '100.00', 'financial_category_id' => $category->id, 'manual_counterparty_name' => 'Intermediador']]]], $requestKey, $user->id);
                    $result = ['ok' => true, 'group' => $group->id];
                } catch (\Throwable $exception) {
                    $result = ['ok' => false, 'exception' => $exception::class, 'message' => $exception->getMessage()];
                }
                DB::disconnect();
                fwrite($sockets[$index][1], json_encode($result, JSON_THROW_ON_ERROR));
                fclose($sockets[$index][1]);
                exit(0);
            }
            $children[] = $pid;
        }
        foreach ($sockets as $pair) {
            fclose($pair[1]);
            stream_set_timeout($pair[0], 60);
            fwrite($pair[0], '1');
        }
        $results = [];
        foreach ($sockets as $pair) {
            $results[] = json_decode(stream_get_contents($pair[0]), true, flags: JSON_THROW_ON_ERROR);
            fclose($pair[0]);
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
        }
        DB::purge();
        $this->assertSame($race === 'same_key' ? 2 : 1, count(array_filter($results, fn ($result) => $result['ok'])), json_encode($results));
        foreach (array_filter($results, fn ($result) => ! $result['ok']) as $failure) {
            $this->assertSame(ValidationException::class, $failure['exception'], json_encode($failure));
        }
        $this->assertSame(1, BankReconciliationGroup::where('company_id', $company->id)->count());
        $this->assertSame(1, AccountPayable::where('company_id', $company->id)->count());
        $this->assertSame(2, CashMovement::where('company_id', $company->id)->count());
        $this->assertSame(0, Money::stored($title->fresh(), 'balance_amount'));
        $this->assertSame(4500.0, $bank->fresh()->current_balance);
    }
}
