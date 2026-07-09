<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stage_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->nullable()->constrained()->nullOnDelete();
            // tie_id sem FK: relação circular com `ties` (ties.match_id aponta de volta)
            $table->unsignedBigInteger('tie_id')->nullable()->index();
            $table->foreignId('home_team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->foreignId('away_team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->unsignedInteger('home_score')->nullable();
            $table->unsignedInteger('away_score')->nullable();
            $table->unsignedInteger('home_penalties')->nullable();
            $table->unsignedInteger('away_penalties')->nullable();
            $table->string('status')->default('scheduled'); // scheduled | live | finished
            $table->timestamp('kickoff_at')->nullable();
            $table->unsignedInteger('version')->default(0); // lock otimista para edição concorrente
            $table->timestamps();

            $table->index(['stage_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matches');
    }
};
