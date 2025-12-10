use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('display_in_showcase')->default(true)->index();
            $table->string('presence')->nullable()->index();
            $table->unsignedInteger('quantity')->default(0)->index();
            $table->unsignedInteger('popularity')->default(0)->index();
            $table->boolean('we_recommended')->default(false)->index();
            $table->string('color')->nullable()->index();
            $table->boolean('in_stock')->default(true)->index(); // зручне агреговане поле
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'display_in_showcase',
                'presence',
                'quantity',
                'popularity',
                'we_recommended',
                'color',
                'in_stock',
            ]);
        });
    }
};
