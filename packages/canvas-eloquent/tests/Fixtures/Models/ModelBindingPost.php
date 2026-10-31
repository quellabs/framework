<?php

	namespace Quellabs\CanvasEloquent\Tests\Fixtures\Models;

	use Illuminate\Database\Eloquent\Model;

	/**
	 * Eloquent model fixture backed by an in-memory SQLite table.
	 */
	class ModelBindingPost extends Model {

		/** @var string */
		protected $table = 'model_binding_test_posts';

		/** @var array<int, string> */
		protected $fillable = ['id', 'title'];

		/** @var bool */
		public $timestamps = false;
	}
