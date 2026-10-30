<?php

	namespace Quellabs\CanvasEloquent\Tests\Fixtures\Models;

	use Illuminate\Database\Eloquent\Model;

	/**
	 * Eloquent model fixture for ModelBindingRequestDispatchTest, backed by a
	 * dedicated table on the project's real configured connection (the same
	 * connection the package's auto-discovered ServiceProvider boots for any
	 * real Kernel), truncated and reseeded before each test.
	 */
	class ModelBindingPost extends Model {

		/** @var string */
		protected $table = 'model_binding_test_posts';

		/** @var array<int, string> */
		protected $fillable = ['id', 'title'];

		/** @var bool */
		public $timestamps = false;
	}
