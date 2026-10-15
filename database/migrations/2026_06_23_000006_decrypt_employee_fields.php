<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ParagonIE\CipherSweet\CipherSweet;
use ParagonIE\CipherSweet\Constants;
use ParagonIE\CipherSweet\EncryptedRow;

return new class extends Migration
{
    private const ENCRYPTED_FIELDS = [
        'socialSecurityNumber',
        'taxIdentificationNumber',
        'passportNumber',
        'votersId',
        'firstName',
        'middleName',
        'lastName',
        'maidenName',
        'notes',
        'health',
        'picturePath',
    ];

    public function up(): void
    {
        if (!Schema::hasTable('employee')) {
            return;
        }

        $encryptedRow = $this->employeeEncryptedRow();

        DB::table('employee')
            ->orderBy('id')
            ->lazyById()
            ->each(function (object $record) use ($encryptedRow) {
                $attributes = (array) $record;

                try {
                    $decrypted = $encryptedRow
                        ->setPermitEmpty((bool) config('ciphersweet.permit_empty', false))
                        ->decryptRow($attributes);
                } catch (Throwable) {
                    return;
                }

                $updates = [];
                foreach (self::ENCRYPTED_FIELDS as $field) {
                    if (array_key_exists($field, $decrypted)) {
                        $updates[$field] = $decrypted[$field];
                    }
                }

                if ($updates !== []) {
                    DB::table('employee')->where('id', $record->id)->update($updates);
                }
            });

        if (Schema::hasTable('blind_indexes')) {
            DB::table('blind_indexes')
                ->where('indexable_type', 'App\\Models\\Employee')
                ->delete();
        }
    }

    public function down(): void
    {
        // Plaintext values cannot be re-encrypted automatically.
    }

    private function employeeEncryptedRow(): EncryptedRow
    {
        $row = new EncryptedRow(app(CipherSweet::class), 'employee');

        return $row
            ->addField('socialSecurityNumber', Constants::TYPE_OPTIONAL_TEXT)
            ->addField('taxIdentificationNumber', Constants::TYPE_OPTIONAL_TEXT)
            ->addField('passportNumber', Constants::TYPE_OPTIONAL_TEXT)
            ->addField('votersId', Constants::TYPE_OPTIONAL_TEXT)
            ->addField('firstName')
            ->addField('middleName', Constants::TYPE_OPTIONAL_TEXT)
            ->addField('lastName')
            ->addField('maidenName', Constants::TYPE_OPTIONAL_TEXT)
            ->addField('notes', Constants::TYPE_OPTIONAL_TEXT)
            ->addField('health', Constants::TYPE_OPTIONAL_TEXT)
            ->addField('picturePath', Constants::TYPE_OPTIONAL_TEXT);
    }
};
