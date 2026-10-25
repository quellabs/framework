<?php

	namespace Quellabs\ObjectQuel\Tests\Support;

	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Execution\Helpers\RoutineCallTyper;
	use Quellabs\ObjectQuel\Execution\Transformers\PaginationTransformer;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\Parser;
	use Quellabs\ObjectQuel\ObjectQuel\Pipeline\DateTimeParameterCoercer;
	use Quellabs\ObjectQuel\ObjectQuel\Pipeline\IdentifierTypeResolver;
	use Quellabs\ObjectQuel\ObjectQuel\Pipeline\QueryNormalizer;
	use Quellabs\ObjectQuel\ObjectQuel\QuelToSQL\QuelToSQLRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\SemanticAnalyzer;
	use Quellabs\ObjectQuel\Planner\QueryOptimizer;

	/**
	 * Compiles a `retrieve` query to SQL for a caller-chosen platform, entirely
	 * offline (no query is ever executed) — for asserting a specific engine's
	 * SQL emission (e.g. SQL Server) without a live connection to that engine.
	 *
	 * Mirrors QueryExecutor's retrieve pipeline (identifier typing, normalization,
	 * semantic validation, optimization, pagination, SQL generation), but threads
	 * one caller-supplied PlatformCapabilitiesInterface through every stage —
	 * unlike QueryExecutor, whose own capabilities are always the real connection's,
	 * with no override seam.
	 */
	class RetrieveSqlCompiler {

		public function __construct(
			private readonly EntityManager $entityManager,
			private readonly PlatformCapabilitiesInterface $platform
		) {
		}

		/**
		 * @param string $query The ObjectQuel `retrieve` query to compile
		 * @param array<string, mixed> $parameters
		 * @return string The generated SQL
		 */
		public function compile(string $query, array $parameters = []): string {
			$entityStore = $this->entityManager->getEntityStore();

			$ast = (new Parser(new Lexer($query), $entityStore))->parse();

			if (!$ast instanceof AstRetrieve) {
				throw new \LogicException('RetrieveSqlCompiler only compiles retrieve statements.');
			}

			(new IdentifierTypeResolver($entityStore))->resolve($ast);
			(new RoutineCallTyper($this->entityManager->getConnection()))->typeCalls($ast);
			(new QueryNormalizer($entityStore))->transform($ast);
			(new DateTimeParameterCoercer())->coerce($ast, $parameters);
			(new SemanticAnalyzer($entityStore, $this->platform))->validate($ast);
			(new QueryOptimizer($this->entityManager, $this->platform))->transform($ast, $parameters);
			(new PaginationTransformer($this->entityManager, $this->platform))->transform($ast, $parameters);

			return (new QuelToSQLRetrieve($entityStore, $parameters, $this->platform))->convertToSQL($ast);
		}
	}
