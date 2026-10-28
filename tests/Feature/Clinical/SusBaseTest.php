<?php

namespace Tests\Feature\Clinical;

use App\Clinical\Cid10;
use App\Models\Professional;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Step 6b.1 (ADR-0011): the professional's Cartão Nacional de Saúde (validated, unique in the network, seen only in
 * the full record, like the CPF) and the official CID-10 table with its search.
 */
class SusBaseTest extends ClinicalTestCase
{
    public function test_the_professional_cns_is_validated_unique_and_kept_out_of_the_short_record(): void
    {
        $update = fn (Professional $professional, string $cns) => $this->actingAs($this->manager->user)->put(route('professionals.update', $professional), [
            'name' => $professional->name, 'base_unit_id' => $professional->base_unit_id, 'cns' => $cns,
        ]);
        $update($this->doctor, '123456789010001')->assertSessionHasErrors(['cns' => 'Cartão Nacional de Saúde inválido.']);
        $update($this->doctor, '1234 5678 9010 000')->assertSessionHasNoErrors();
        $this->assertSame('123456789010000', $this->doctor->refresh()->cns);
        // A provisional card (7, 8 or 9) too; the same card twice in the network is refused.
        $update($this->nurse, '700123456780900')->assertSessionHasNoErrors();
        $update($this->nurse, '123456789010000')->assertSessionHasErrors(['cns' => 'Já existe um profissional com este Cartão Nacional de Saúde.']);
        // Cleared with an empty field.
        $update($this->nurse, '')->assertSessionHasNoErrors();
        $this->assertNull($this->nurse->refresh()->cns);

        $this->get(route('professionals.show', $this->doctor))->assertInertia(fn (Assert $page) => $page->where('professional.cns', '123456789010000'));
    }

    public function test_the_cid10_table_finds_codes_and_names(): void
    {
        $this->assertSame('A000', Cid10::normalize(' a00.0 '));
        $this->assertNull(Cid10::normalize('A00'));
        $this->assertSame('A33', Cid10::normalize('A33'));
        $this->assertSame('Cólera devida a Vibrio cholerae 01, biótipo cholerae', Cid10::name('A000'));
        $this->assertSame('F', Cid10::sex('O800'));
        $this->assertNull(Cid10::sex('J189'));
        $this->assertSame(['A000', 'A001', 'A009'], array_column(Cid10::search('a00'), 'code'));
        // Words in any order, accents ignored.
        $this->assertContains('J189', array_column(Cid10::search('pneumonia nao especificada'), 'code'));
        $this->assertCount(5, Cid10::search('febre', 5));
        $this->assertSame([], Cid10::search('a'));
        $this->assertGreaterThan(12000, count(Cid10::all()));
    }
}
