<?php

	namespace App\Models;

	use Illuminate\Database\Eloquent\Model;

	/**
	 * Plain Eloquent model over the 'posts' table, used to exercise canvas-eloquent's eager Capsule boot.
	 */
	class Post extends Model {

		/**
		 * @var string
		 */
		protected $table = 'posts';

		/**
		 * @var bool
		 */
		public $timestamps = false;

		/**
		 * @var array<int, string>
		 */
		protected $fillable = ['title', 'content', 'published'];
	}
