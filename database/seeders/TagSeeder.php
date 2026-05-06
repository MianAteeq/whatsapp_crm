<?php

namespace Database\Seeders;

use App\Models\Tag;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class TagSeeder extends Seeder
{
    public function run(): void
    {

       
        $tags = [

            'VIP',
            'Eid Promo',
            'New Customer',
            'Wholesale',
            'Complaint',
            'Inactive',
            'High Spender',
            'Recent Lead',
            'Returning Customer',
            'Hot Lead'

        ];

        foreach ($tags as $tag) {

            Tag::create([

               

                'name' => $tag

            ]);

        }

    }
}