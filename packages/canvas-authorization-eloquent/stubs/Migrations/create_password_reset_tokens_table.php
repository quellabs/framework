<?php

	use Illuminate\Database\Migrations\Migration;
	use Illuminate\Database\Schema\Blueprint;
	use Illuminate\Support\Facades\Schema;

	/**
	 * token_hash is unique so two concurrently issued tokens can never
	 * collide; user_id is indexed since every lookup after a reset filters
	 * on it to invalidate a user's other outstanding tokens.
	 */
	return new class extends Migration {

		/**
		 * Run the migrations.
		 * @return void
		 */
		public function up(): void {
			Schema::create('password_reset_tokens', function (Blueprint $table) {
				$table->id();
				$table->unsignedInteger('user_id')->index();
				$table->string('token_hash', 64)->unique();
				$table->dateTime('expires_at');
				$table->dateTime('used_at')->nullable();
				$table->dateTime('created_at');
			});
		}

		/**
		 * Reverse the migrations.
		 * @return void
		 */
		public function down(): void {
			Schema::dropIfExists('password_reset_tokens');
		}
	};
