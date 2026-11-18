<?php

	use Illuminate\Database\Migrations\Migration;
	use Illuminate\Database\Schema\Blueprint;
	use Illuminate\Support\Facades\Schema;

	/**
	 * Unique, not a plain index: without a DB-enforced constraint, two
	 * concurrent registrations for the same username could both pass
	 * AuthenticationController::findUser()'s read-then-write check and
	 * both insert, producing two accounts with the same login identifier.
	 */
	return new class extends Migration {

		/**
		 * Run the migrations.
		 * @return void
		 */
		public function up(): void {
			Schema::create('users', function (Blueprint $table) {
				$table->id();
				$table->string('name');
				$table->string('username')->unique();
				$table->string('password');
				$table->boolean('banned')->default(false);
			});
		}

		/**
		 * Reverse the migrations.
		 * @return void
		 */
		public function down(): void {
			Schema::dropIfExists('users');
		}
	};
