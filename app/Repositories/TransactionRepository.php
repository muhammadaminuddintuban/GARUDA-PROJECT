<?php 

namespace App\Repositories;

use App\Interfaces\TransactionRepositoryInterface;
use App\Jobs\SendMailTransactionSuccessJob;
use App\Models\Transaction;
use App\Models\TransactionPassenger;
use App\Models\FlightClass;
use App\Models\PromoCode;

class TransactionRepository implements TransactionRepositoryInterface
{
    public function getTransactionDataFromSession()
    {
        return session()->get('transaction', []);
    }

    public function saveTransactionDataToSession($data)
    {
        $transaction = [];
        foreach ($data as $key => $value) 
        {
            $transaction[$key] = $value;
        }
        session()->put('transaction', $transaction);
        return $transaction;
    }

    public function saveTransaction($data)
    {
        $data['code'] = $this->generateTransactionCode();
        $data['number_of_passengers'] = $this->countPassengers($data['passengers']);

       // Hitung subtotal dan grand total awal
        $data['subtotal'] = $this->calculateSubtotal($data['flight_class_id'], $data['number_of_passengers']);
        $data['grandtotal'] = $data['subtotal'];

       // Terapkan promo jika ada
        if (!empty($data['promo_code'])) 
        {
            $data = $this->applyPromoCode($data);
        }

        // Tambahkan PPN
        $data['grandtotal'] = $this->addPPN($data['grandtotal']);

       // Simpan transaksi dan penumpang
        $transaction = $this->createTransaction($data);
        $this->savePassengers($data['passengers'], $transaction->id);

        session()->forget('transaction');

        return $transaction;
    }

    private function generateTransactionCode()
    {
        return "BWAGARUDA" . rand(1000, 9999);
    }

    private function countPassengers($passengers)
    {
        return count($passengers);
    }

    private function calculateSubtotal($flightClassId, $numberOfPassengers)
    {
        $price = FlightClass::findOrFail($flightClassId)->price;
       return $price * $numberOfPassengers;
    }

    private function applyPromoCode($data)
    {
        $promo = PromoCode::where('code', $data['promo_code'])
            ->where('valid_until', '>=', now())
            ->where('is_used', false)
            ->first();

        if ($promo) 
        {
            if ($promo->discount_type === 'percentage') 
            {
               $data['discount'] = $data['grandtotal'] * ($promo->discount / 100);
            } else {
                $data['discount'] = $promo->discount;
            }

            $data['grandtotal'] -= $data['discount'];
            $data['promo_code_id'] = $promo->id;

           // Tandai promo code sebagai sudah digunakan
            $promo->update(['is_used' => true]);
        }
        return $data;
    }

    private function addPPN($grandTotal)
    {
        $ppn = $grandTotal * 0.11;
        return $grandTotal + $ppn;
    }

    private function createTransaction($data)
    {
        return Transaction::create($data);
    }

    private function savePassengers($passengers, $transactionId)
    {
        foreach ($passengers as $passenger) 
        {
            $passenger['transaction_id'] = $transactionId;
            TransactionPassenger::create($passenger);
        }
    }

    public function gettransactionByCode($code)
    {
        return Transaction::where('code', $code)->first();
    }

    public function getTransactionByCodeEmailPhone($code, $email, $phone)
    {
        return Transaction::where('code', $code)
            ->where('email', $email)
            ->where('phone_number', $phone)
            ->first();
    }
}
?>