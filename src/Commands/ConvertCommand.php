<?php declare(strict_types=1);

namespace Fjbender\Settlement2csv\Commands;

use Fjbender\Settlement2csv\Command;
use Mollie\Api\Exceptions\ApiException;
use Mollie\Api\MollieApiClient;
use Mollie\Api\Resources\BaseResource;
use Mollie\Api\Resources\Capture;
use Mollie\Api\Resources\Chargeback;
use Mollie\Api\Resources\Payment;
use Mollie\Api\Resources\Refund;
use Mollie\Api\Resources\Settlement;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ConvertCommand extends Command
{
    private MollieApiClient $mollie;

    public function __construct()
    {
        parent::__construct();
        $this->mollie = new MollieApiClient();
        $this->mollie->setAccessToken($_ENV['MOLLIE_ORGANIZATIONAL_ACCESS_TOKEN']);
    }

    public function configure()
    {
        $this->setName('convert')
            ->setDescription('Imports a settlement from Mollie\'s API and converts it to a CSV file')
            ->setHelp('This command allows you to import a settlement from Mollie\'s API and convert it to a CSV file')
            ->addArgument('settlementId', InputArgument::OPTIONAL, 'The Settlement ID (e.g. stl_foobar), default is latest settlement');
    }

    /**
     * @throws ApiException
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $settlement = $this->mollie->settlements->get($input->getArgument('settlementId') ?? $this->getLatestSettlementId());
        $output->writeln('<info>Grabbing Settlement ID: ' . $settlement->id . '</info>');

        if (file_exists($settlement->id . '.csv')) {
            $output->writeln('<error>File already exists</error>');
            return 1;
        }

        $output->writeln('<info>Writing to file: ' . $settlement->id . '.csv</info>');
        $totalTransactionsExpected = $this->countTransactionsInSettlement($settlement);
        $output->writeln('<info>Expecting about ' . $totalTransactionsExpected . ' transactions</info>');

        ProgressBar::setFormatDefinition('custom', "%current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s%" . PHP_EOL . "%message%");
        $progressBar = new ProgressBar($output, $totalTransactionsExpected);
        $progressBar->setFormat('custom');

        $transactions = array();
        foreach(array('captures', 'payments', 'refunds', 'chargebacks') as $transactionType) {
            $counter = 0;
            $progressBar->setMessage('Grabbing ' . $transactionType);
            $transactionsOnApi = $settlement->{$transactionType}();
            do {
                foreach ($transactionsOnApi as $transaction) {
                    if ($transaction->id !== null) {
                        // skip failed refunds
                        if ($transaction instanceof Refund && $transaction->status === 'failed') {
                            continue;
                        }
                        $transactions[] = $this->mapTransactionToArray($transaction, $settlement);
                        $counter++;
                        $progressBar->advance();
                    }
                }
            } while ($transactionsOnApi = $transactionsOnApi->next());
        }
        $progressBar->setMessage('Writing output file');
        $outputFile = fopen($settlement->id . '.csv', 'w');
        fwrite($outputFile, 'Date,Payment method,Currency,Amount,Status,ID,Description,Consumer name,Consumer bank account,Consumer BIC,Settlement currency,Settlement amount,Settlement reference,Amount refunded' . PHP_EOL);
        foreach ($transactions as $transaction) {
            fputcsv($outputFile, $transaction);
        }

        $progressBar->setMessage('Calculating withheld fees');
        // Get cost information from settlement
        $withheldFees = array();
        $settlementPeriods = json_decode(json_encode($settlement->periods), true);
        foreach($settlementPeriods as $year => $months) {
            foreach ($months as $month => $monthlySettlement) {
                $withheldFees[$monthlySettlement['invoiceReference']] = 0;
                foreach ($monthlySettlement['costs'] as $cost) {
                    $withheldFees[$monthlySettlement['invoiceReference']] -= $cost['amountGross']['value'];
                }
            }
        }
        foreach ($withheldFees as $invoiceReference => $amount) {
            fputcsv($outputFile, $this->mapWithheldFeesToArray($invoiceReference, round($amount, 2), $settlement));
        }
        fclose($outputFile);
        $progressBar->setMessage('Done');
        $progressBar->finish();
        return 0;
    }

    /**
     * @throws ApiException
     */
    private function getLatestSettlementId(): string
    {
        $settlements = $this->mollie->settlements->page();
        $latestSettlement = $settlements[0];
        return $latestSettlement->id;
    }

    private function countTransactionsInSettlement(Settlement $settlement): int
    {
        $count = 0;
        $settlementPeriods = json_decode(json_encode($settlement->periods), true);
        foreach($settlementPeriods as $year => $months) {
            foreach ($months as $month => $monthlySettlement) {
                foreach ($monthlySettlement['revenue'] as $revenue) {
                    $count += $revenue['count'];
                }
            }
        }
        return $count;
    }

    /*
     * Maps a transaction to an array that later becomes a line in the CSV file.
     * Looking up the payment description for each capture is a bit nasty, but the information
     * can't be retrieved from the capture alone.
     */
    private function mapTransactionToArray(BaseResource $transaction, Settlement $settlement): array
    {
        // todo receive more details about chargebacks
        // Date
        $transactionArray[0] = $transaction->createdAt; // todo format date
        // Payment Method
        if ($transaction instanceof Payment) {
            $transactionArray[1] = $transaction->method;
        } elseif ($transaction instanceof Refund) {
            $transactionArray[1] = 'refund';
        } elseif ($transaction instanceof Chargeback) {
            $transactionArray[1] = 'chargeback';
        } else {
            $transactionArray[1] = null;
        }
        // Currency
        $transactionArray[2] = $transaction->amount->currency;
        // Amount
        if ($transaction instanceof Chargeback || $transaction instanceof Refund) {
            $transactionArray[3] = $transaction->amount->value * -1;
        } else {
            $transactionArray[3] = $transaction->amount->value;
        }
        // Status
        $transactionArray[4] = $transaction->status ?? null;
        // ID
        $transactionArray[5] = $transaction->id;
        // Description
        if ($transaction instanceof Payment || $transaction instanceof Refund) {
            $transactionArray[6] = $transaction->description;
        } elseif ($transaction instanceof Capture) {
            $transactionArray[6] = 'Original payment: ' . $transaction->paymentId . ' - ' .
                $this->mollie->payments->get($transaction->paymentId)->description;
        } else {
            $transactionArray[6] = null;
        }
        // Consumer Name
        $transactionArray[7] = $transaction->details->consumerName ?? null;
        // Consumer Bank Account
        $transactionArray[8] = $transaction->details->consumerAccount ?? null;
        // Consumer BIC
        $transactionArray[9] = $transaction->details->consumerBic ?? null;
        // Settlement Currency
        $transactionArray[10] = $transaction->settlementAmount->currency;
        // Settlement Amount
        $transactionArray[11] = $transaction->settlementAmount->value;
        // Settlement Reference
        $transactionArray[12] = $settlement->reference;
        // Amount refunded
        $transactionArray[13] = $transaction->amountRefunded->value ?? null;

        return $transactionArray;
    }

    private function mapWithheldFeesToArray(string $invoiceReference, float $withheldFees, Settlement $settlement): array
    {
        // Date
        $withheldFeesArray[0] = $settlement->createdAt;
        // Payment Method
        $withheldFeesArray[1] = null;
        // Currency
        $withheldFeesArray[2] = 'EUR'; // todo check if currency is always EUR
        // Amount
        $withheldFeesArray[3] = $withheldFees;
        // Status
        $withheldFeesArray[4] = null;
        // ID
        $withheldFeesArray[5] = null;
        // Description
        $withheldFeesArray[6] = 'Withheld transaction fees ' . $invoiceReference;
        // Consumer Name
        $withheldFeesArray[7] = null;
        // Consumer Bank Account
        $withheldFeesArray[8] = null;
        // Consumer BIC
        $withheldFeesArray[9] = null;
        // Settlement Currency
        $withheldFeesArray[10] = 'EUR'; // todo check if currency is always EUR
        // Settlement Amount
        $withheldFeesArray[11] = $withheldFees;
        // Settlement Reference
        $withheldFeesArray[12] = $settlement->reference;
        // Amount refunded
        $withheldFeesArray[13] = null;

        return $withheldFeesArray;
    }
}