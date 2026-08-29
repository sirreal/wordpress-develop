<?php
namespace ComponentFuzz\Surfaces;

$ai_client_autoload = dirname( __DIR__, 3 ) . '/src/wp-includes/php-ai-client/autoload.php';
if ( file_exists( $ai_client_autoload ) ) {
	require_once $ai_client_autoload;
}
unset( $ai_client_autoload );

/**
 * Fuzzes the no-DB WordPress AI Client public APIs and bundled SDK DTOs.
 */
final class AiClientSurface {
	public const NAME = 'ai-client';

	public const PROVIDER_ID = 'component-fuzz-ai';
	public const MODEL_ID    = 'component-fuzz-text';

	public const COLLISION_PROVIDER_A_ID = 'component-fuzz-collision-a';
	public const COLLISION_PROVIDER_B_ID = 'component-fuzz-collision-b';
	public const COLLISION_SHARED_MODEL  = 'component-fuzz-shared-model';
	public const COLLISION_A_ONLY_MODEL  = 'component-fuzz-a-only-model';
	public const COLLISION_B_ONLY_MODEL  = 'component-fuzz-b-only-model';

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'ai-client.bootstrap-apis-available',
					'Required WordPress AI Client APIs are unavailable.',
					array( 'missing' => $missing )
				),
			);
		}

		$snapshot = self::snapshot_state();
		$rows     = array();

		try {
			AiClientSurface_FakeProvider::reset();

			$rows[] = self::check_dto_round_trips( $ctx );
			$rows[] = self::check_invalid_value_rejection( $ctx );
			$rows[] = self::check_enum_strictness( $ctx );
			$rows[] = self::check_provider_registry_isolation( $ctx );
			$rows[] = self::check_model_selection_preferences_and_provider_collisions( $ctx );
			$rows[] = self::check_prompt_builder_and_events( $ctx );
			$rows[] = self::check_ability_resolver_integration( $ctx );
			$rows[] = self::check_cache_and_dispatcher_adapters( $ctx );
			$rows[] = self::check_http_transport_bridge( $ctx );
		} catch ( \Throwable $e ) {
			$rows[] = $ctx->fail(
				'ai-client.surface-no-throw',
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			self::restore_state( $snapshot );
			AiClientSurface_FakeProvider::reset();
		}

		return $rows;
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach (
			array(
				'WordPress\AiClient\AiClient',
				'WordPress\AiClient\Messages\DTO\Message',
				'WordPress\AiClient\Messages\DTO\MessagePart',
				'WordPress\AiClient\Files\DTO\File',
				'WordPress\AiClient\Tools\DTO\FunctionCall',
				'WordPress\AiClient\Tools\DTO\FunctionDeclaration',
				'WordPress\AiClient\Tools\DTO\FunctionResponse',
				'WordPress\AiClient\Providers\ProviderRegistry',
				'WordPress\AiClient\Providers\Http\HttpTransporter',
				'WordPress\AiClient\Providers\Http\DTO\Request',
				'WordPress\AiClient\Providers\Http\DTO\RequestOptions',
				'WordPress\AiClient\Providers\Http\DTO\Response',
				'WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum',
				'WordPress\AiClient\Providers\Http\Exception\NetworkException',
				'WordPress\AiClient\Providers\Models\DTO\ModelConfig',
				'WordPress\AiClient\Results\DTO\GenerativeAiResult',
				'WordPress\AiClientDependencies\Http\Discovery\ClassDiscovery',
				'WordPress\AiClientDependencies\Http\Discovery\Psr18ClientDiscovery',
				'WordPress\AiClientDependencies\Nyholm\Psr7\Factory\Psr17Factory',
				'WP_AI_Client_Ability_Function_Resolver',
				'WP_AI_Client_Cache',
				'WP_AI_Client_Discovery_Strategy',
				'WP_AI_Client_Event_Dispatcher',
				'WP_AI_Client_HTTP_Client',
				'WP_AI_Client_Prompt_Builder',
				'WP_Ability',
				'WP_Abilities_Registry',
				'WP_Ability_Categories_Registry',
				'WP_Error',
			) as $class
		) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'WordPress\AiClient\Providers\Http\Contracts\ClientWithOptionsInterface',
				'WordPress\AiClientDependencies\Psr\Http\Client\ClientInterface',
				'WordPress\AiClientDependencies\Psr\Http\Message\RequestFactoryInterface',
				'WordPress\AiClientDependencies\Psr\Http\Message\ResponseFactoryInterface',
				'WordPress\AiClientDependencies\Psr\Http\Message\StreamFactoryInterface',
			) as $interface
		) {
			if ( ! interface_exists( $interface ) ) {
				$missing[] = "interface {$interface}";
			}
		}

		foreach (
			array(
				'__',
				'add_action',
				'add_filter',
				'apply_filters',
				'do_action',
				'is_wp_error',
				'remove_action',
				'remove_filter',
				'wp_ai_client_prompt',
				'wp_cache_get',
				'wp_cache_set',
				'wp_cache_delete',
				'wp_cache_get_multiple',
				'wp_cache_set_multiple',
				'wp_cache_delete_multiple',
				'wp_cache_supports',
				'wp_remote_retrieve_body',
				'wp_remote_retrieve_headers',
				'wp_remote_retrieve_response_code',
				'wp_remote_retrieve_response_message',
				'wp_safe_remote_request',
				'wp_get_ability',
				'wp_register_ability',
				'wp_register_ability_category',
				'wp_prepare_json_schema_for_client',
				'wp_supports_ai',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		return $missing;
	}

	private static function check_dto_round_trips( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$cases    = self::dto_cases( $ctx->fork( 'dto-cases' ) );

		foreach ( $cases as $case ) {
			$class = $case['class'];

			try {
				$first      = $class::fromArray( $case['input'] );
				$canonical  = $first->toArray();
				$second     = $class::fromArray( $canonical );
				$round_trip = $second->toArray();
				$json       = json_decode(
					wp_json_encode(
						$first,
						JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
					),
					true
				);

				self::collect_failure(
					$failures,
					self::same_value( $canonical, $round_trip ),
					"{$case['label']} canonical fromArray/toArray is idempotent",
					array(
						'canonical' => $canonical,
						'roundTrip' => $round_trip,
					)
				);

				self::collect_failure(
					$failures,
					self::same_value( $canonical, $json ),
					"{$case['label']} json serialization matches canonical array",
					array(
						'canonical' => $canonical,
						'json'      => $json,
					)
				);

				self::collect_failure(
					$failures,
					true === $class::isArrayShape( $canonical ),
					"{$case['label']} reports its canonical array as valid shape",
					array( 'canonical' => $canonical )
				);

				foreach ( $case['canonicalKeys'] as $key ) {
					self::collect_failure(
						$failures,
						array_key_exists( $key, $canonical ),
						"{$case['label']} canonical array includes {$key}",
						array( 'canonical' => $canonical )
					);
				}

				$schema = $class::getJsonSchema();
				foreach ( $case['schemaRequiredSets'] as $required_set ) {
					self::collect_failure(
						$failures,
						self::schema_declares_required_set( $schema, $required_set ),
						"{$case['label']} schema declares required keys",
						array(
							'requiredSet' => $required_set,
							'schema'      => $schema,
						)
					);
				}
			} catch ( \Throwable $e ) {
				$failures[] = array(
					'label'     => "{$case['label']} round trip threw",
					'throwable' => self::describe_throwable( $e ),
					'input'     => $case['input'],
				);
			}
		}

		return self::result(
			$ctx,
			'ai-client.dto-round-trips-and-schema-required-keys',
			array() === $failures,
			array(
				'cases'    => count( $cases ),
				'failures' => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function check_invalid_value_rejection( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$invalids = array(
			'message.invalid-role'            => static function (): void {
				\WordPress\AiClient\Messages\DTO\Message::fromArray(
					array(
						'role'  => 'administrator',
						'parts' => array(),
					)
				);
			},
			'message-part.missing-content'    => static function (): void {
				\WordPress\AiClient\Messages\DTO\MessagePart::fromArray(
					array(
						'channel' => 'content',
						'type'    => 'text',
					)
				);
			},
			'user-message.function-call'      => static function (): void {
				new \WordPress\AiClient\Messages\DTO\UserMessage(
					array(
						new \WordPress\AiClient\Messages\DTO\MessagePart(
							new \WordPress\AiClient\Tools\DTO\FunctionCall( 'call', 'tool', array() )
						),
					)
				);
			},
			'model-message.function-response' => static function (): void {
				new \WordPress\AiClient\Messages\DTO\ModelMessage(
					array(
						new \WordPress\AiClient\Messages\DTO\MessagePart(
							new \WordPress\AiClient\Tools\DTO\FunctionResponse( 'call', 'tool', array() )
						),
					)
				);
			},
			'candidate.user-message'          => static function (): void {
				new \WordPress\AiClient\Results\DTO\Candidate(
					new \WordPress\AiClient\Messages\DTO\UserMessage(
						array( new \WordPress\AiClient\Messages\DTO\MessagePart( 'not a model reply' ) )
					),
					\WordPress\AiClient\Results\Enums\FinishReasonEnum::stop()
				);
			},
			'file.base64-without-mime'        => static function () use ( $ctx ): void {
				new \WordPress\AiClient\Files\DTO\File( base64_encode( $ctx->fork( 'bad-file' )->ascii( 3, 8 ) ) );
			},
			'function-call.no-id-or-name'     => static function (): void {
				new \WordPress\AiClient\Tools\DTO\FunctionCall();
			},
			'function-response.no-id-or-name' => static function (): void {
				\WordPress\AiClient\Tools\DTO\FunctionResponse::fromArray( array( 'response' => true ) );
			},
			'provider.invalid-id'             => static function (): void {
				new \WordPress\AiClient\Providers\DTO\ProviderMetadata(
					'Bad Provider',
					'Bad',
					\WordPress\AiClient\Providers\Enums\ProviderTypeEnum::server()
				);
			},
			'model-config.bad-aspect-ratio'   => static function (): void {
				\WordPress\AiClient\Providers\Models\DTO\ModelConfig::fromArray(
					array(
						'outputMediaOrientation' => 'square',
						'outputMediaAspectRatio' => '16:9',
					)
				);
			},
			'model-config.non-list-modalities' => static function (): void {
				$config = new \WordPress\AiClient\Providers\Models\DTO\ModelConfig();
				$config->setOutputModalities(
					array( 'text' => \WordPress\AiClient\Messages\Enums\ModalityEnum::text() )
				);
			},
			'supported-option.non-list-values' => static function (): void {
				new \WordPress\AiClient\Providers\Models\DTO\SupportedOption(
					\WordPress\AiClient\Providers\Models\Enums\OptionEnum::temperature(),
					array( 'low' => 0.1 )
				);
			},
			'model-metadata.non-list-capabilities' => static function (): void {
				new \WordPress\AiClient\Providers\Models\DTO\ModelMetadata(
					'bad',
					'Bad',
					array( 'text' => \WordPress\AiClient\Providers\Models\Enums\CapabilityEnum::textGeneration() ),
					array()
				);
			},
			'result.empty-candidates'         => static function (): void {
				new \WordPress\AiClient\Results\DTO\GenerativeAiResult(
					'empty',
					array(),
					new \WordPress\AiClient\Results\DTO\TokenUsage( 1, 1, 2 ),
					AiClientSurface_FakeProvider::metadata(),
					AiClientSurface_FakeProvider::modelMetadata()
				);
			},
			'operation.succeeded-without-result' => static function (): void {
				\WordPress\AiClient\Operations\DTO\GenerativeAiOperation::fromArray(
					array(
						'id'    => 'operation',
						'state' => 'succeeded',
					)
				);
			},
			'request-options.negative-timeout' => static function (): void {
				\WordPress\AiClient\Providers\Http\DTO\RequestOptions::fromArray( array( 'timeout' => -0.1 ) );
			},
			'request-options.negative-redirects' => static function (): void {
				\WordPress\AiClient\Providers\Http\DTO\RequestOptions::fromArray( array( 'maxRedirects' => -1 ) );
			},
			'request.invalid-method'          => static function (): void {
				\WordPress\AiClient\Providers\Http\DTO\Request::fromArray(
					array(
						'method'  => 'FETCH',
						'uri'     => 'https://example.test/api',
						'headers' => array(),
					)
				);
			},
			'response.invalid-status'         => static function (): void {
				\WordPress\AiClient\Providers\Http\DTO\Response::fromArray(
					array(
						'statusCode' => 99,
						'headers'    => array(),
					)
				);
			},
			'registry.invalid-provider-class' => static function (): void {
				$registry = new \WordPress\AiClient\Providers\ProviderRegistry();
				$registry->registerProvider( \stdClass::class );
			},
		);

		foreach ( $invalids as $label => $callback ) {
			self::collect_failure(
				$failures,
				self::throws( $callback ),
				"{$label} rejects invalid input",
				array( 'label' => $label )
			);
		}

		return self::result(
			$ctx,
			'ai-client.invalid-inputs-are-rejected',
			array() === $failures,
			array(
				'cases'    => count( $invalids ),
				'failures' => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function check_enum_strictness( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures     = array();
		$enum_classes = array(
			'WordPress\AiClient\Files\Enums\FileTypeEnum',
			'WordPress\AiClient\Files\Enums\MediaOrientationEnum',
			'WordPress\AiClient\Messages\Enums\MessagePartChannelEnum',
			'WordPress\AiClient\Messages\Enums\MessagePartTypeEnum',
			'WordPress\AiClient\Messages\Enums\MessageRoleEnum',
			'WordPress\AiClient\Messages\Enums\ModalityEnum',
			'WordPress\AiClient\Operations\Enums\OperationStateEnum',
			'WordPress\AiClient\Providers\Enums\ProviderTypeEnum',
			'WordPress\AiClient\Providers\Enums\ToolTypeEnum',
			'WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum',
			'WordPress\AiClient\Providers\Http\Enums\RequestAuthenticationMethod',
			'WordPress\AiClient\Providers\Models\Enums\CapabilityEnum',
			'WordPress\AiClient\Providers\Models\Enums\OptionEnum',
			'WordPress\AiClient\Results\Enums\FinishReasonEnum',
		);

		foreach ( $enum_classes as $class ) {
			$values = $class::getValues();
			$cases  = $class::cases();

			self::collect_failure(
				$failures,
				array() !== $values && count( $values ) === count( $cases ),
				"{$class} values and cases agree",
				array(
					'values' => $values,
					'cases'  => count( $cases ),
				)
			);

			foreach ( $cases as $case ) {
				self::collect_failure(
					$failures,
					$class::from( $case->value ) === $case
						&& $class::tryFrom( $case->value ) === $case
						&& true === $class::isValidValue( $case->value )
						&& $case->equals( $case->value )
						&& (string) $case === $case->value
						&& json_decode( wp_json_encode( $case ), true ) === $case->value,
					"{$class} case {$case->name} is strict and serializable",
					array(
						'name'  => $case->name,
						'value' => $case->value,
					)
				);
			}

			$invalid = '__invalid_' . dechex( $ctx->seed() ) . '_' . str_replace( '\\', '_', strtolower( $class ) );
			self::collect_failure(
				$failures,
				null === $class::tryFrom( $invalid )
					&& false === $class::isValidValue( $invalid )
					&& self::throws( static fn() => $class::from( $invalid ) ),
				"{$class} rejects invalid backing values",
				array( 'invalid' => $invalid )
			);
		}

		self::collect_failure(
			$failures,
			\WordPress\AiClient\Providers\Models\Enums\OptionEnum::maxTokens()->value
				=== \WordPress\AiClient\Providers\Models\DTO\ModelConfig::KEY_MAX_TOKENS
				&& \WordPress\AiClient\Providers\Models\Enums\OptionEnum::maxTokens()->isMaxTokens(),
			'OptionEnum dynamically exposes ModelConfig KEY_* constants',
			array(
				'maxTokens' => \WordPress\AiClient\Providers\Models\Enums\OptionEnum::maxTokens()->value,
			)
		);

		return self::result(
			$ctx,
			'ai-client.enums-are-strict-singleton-values',
			array() === $failures,
			array(
				'classes'  => count( $enum_classes ),
				'failures' => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function check_provider_registry_isolation( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();

		self::set_static_property( \WordPress\AiClient\AiClient::class, 'defaultRegistry', null );
		AiClientSurface_FakeProvider::set_configured( true );

		$registry_a = new \WordPress\AiClient\Providers\ProviderRegistry();
		$registry_b = new \WordPress\AiClient\Providers\ProviderRegistry();

		$registry_a->registerProvider( AiClientSurface_FakeProvider::class );

		$default_registry_1 = \WordPress\AiClient\AiClient::defaultRegistry();
		$default_registry_2 = \WordPress\AiClient\AiClient::defaultRegistry();
		$default_registry_1->registerProvider( AiClientSurface_FakeProvider::class );

		$requirements = new \WordPress\AiClient\Providers\Models\DTO\ModelRequirements(
			array( \WordPress\AiClient\Providers\Models\Enums\CapabilityEnum::textGeneration() ),
			array()
		);

		$model      = $registry_a->getProviderModel( self::PROVIDER_ID, self::MODEL_ID, self::model_config( $ctx ) );
		$matches    = $registry_a->findModelsMetadataForSupport( $requirements );
		$configured = $registry_a->isProviderConfigured( self::PROVIDER_ID );

		AiClientSurface_FakeProvider::set_configured( false );
		$unconfigured_matches = $registry_a->findModelsMetadataForSupport( $requirements );
		$unconfigured         = $registry_a->isProviderConfigured( self::PROVIDER_ID );
		AiClientSurface_FakeProvider::set_configured( true );

		self::collect_failure(
			$failures,
			$registry_a->hasProvider( self::PROVIDER_ID )
				&& $registry_a->hasProvider( AiClientSurface_FakeProvider::class )
				&& AiClientSurface_FakeProvider::class === $registry_a->getProviderClassName( self::PROVIDER_ID )
				&& self::PROVIDER_ID === $registry_a->getProviderId( AiClientSurface_FakeProvider::class )
				&& array( self::PROVIDER_ID ) === $registry_a->getRegisteredProviderIds(),
			'registered provider is discoverable by ID and class',
			array( 'ids' => $registry_a->getRegisteredProviderIds() )
		);

		self::collect_failure(
			$failures,
			! $registry_b->hasProvider( self::PROVIDER_ID )
				&& self::throws( static fn() => $registry_b->getProviderClassName( self::PROVIDER_ID ) ),
			'separate ProviderRegistry instances do not share registered providers',
			array( 'registryBIds' => $registry_b->getRegisteredProviderIds() )
		);

		self::collect_failure(
			$failures,
			$default_registry_1 === $default_registry_2
				&& $default_registry_1 !== $registry_a
				&& $default_registry_1->hasProvider( self::PROVIDER_ID )
				&& ! $registry_b->hasProvider( self::PROVIDER_ID ),
			'AiClient default registry is singleton state but isolated from custom registries',
			array(
				'defaultIds' => $default_registry_1->getRegisteredProviderIds(),
				'customIds'  => $registry_b->getRegisteredProviderIds(),
			)
		);

		self::collect_failure(
			$failures,
			$model instanceof AiClientSurface_FakeTextModel
				&& self::same_value( self::model_config( $ctx )->toArray(), $model->getConfig()->toArray() ),
			'registry returns configured model instances without network dependencies',
			array(
				'modelClass' => is_object( $model ) ? get_class( $model ) : gettype( $model ),
				'config'     => $model instanceof AiClientSurface_FakeTextModel ? $model->getConfig()->toArray() : null,
			)
		);

		self::collect_failure(
			$failures,
			$configured
				&& ! $unconfigured
				&& 1 === count( $matches )
				&& array() === $unconfigured_matches,
			'provider availability gates metadata lookup without mutating registration',
			array(
				'matches'              => count( $matches ),
				'unconfiguredMatches'  => count( $unconfigured_matches ),
				'stillRegisteredAfter' => $registry_a->hasProvider( self::PROVIDER_ID ),
			)
		);

		return self::result(
			$ctx,
			'ai-client.provider-registry-registration-lookup-and-isolation',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 8 ) )
		);
	}

	private static function check_model_selection_preferences_and_provider_collisions(
		\ComponentFuzz\FuzzContext $ctx
	): array {
		$failures = array();
		$case     = $ctx->fork( 'model-selection' );
		$registry = new \WordPress\AiClient\Providers\ProviderRegistry();

		$registry->registerProvider( AiClientSurface_CollisionProviderA::class );
		$registry->registerProvider( AiClientSurface_CollisionProviderB::class );

		$reversed_registry = new \WordPress\AiClient\Providers\ProviderRegistry();
		$reversed_registry->registerProvider( AiClientSurface_CollisionProviderB::class );
		$reversed_registry->registerProvider( AiClientSurface_CollisionProviderA::class );

		$prompt  = 'Choose model ' . self::safe_text( $case->fork( 'prompt' ), 5, 24 );
		$history = new \WordPress\AiClient\Messages\DTO\UserMessage(
			array(
				new \WordPress\AiClient\Messages\DTO\MessagePart(
					'History ' . self::safe_text( $case->fork( 'history' ), 4, 18 )
				),
			)
		);

		$model_only = ( new \WP_AI_Client_Prompt_Builder( $registry, $prompt ) )
			->with_history( $history )
			->using_model_preference( self::COLLISION_SHARED_MODEL )
			->generate_text_result();

		$provider_tuple = ( new \WP_AI_Client_Prompt_Builder( $registry, $prompt ) )
			->with_history( $history )
			->using_model_preference(
				array( self::COLLISION_PROVIDER_B_ID, self::COLLISION_SHARED_MODEL )
			)
			->generate_text_result();

		$model_instance = AiClientSurface_CollisionProviderB::model(
			self::COLLISION_SHARED_MODEL,
			new \WordPress\AiClient\Providers\Models\DTO\ModelConfig()
		);
		$model_instance_preference = ( new \WP_AI_Client_Prompt_Builder( $registry, $prompt ) )
			->with_history( $history )
			->using_model_preference( $model_instance )
			->generate_text_result();

		$provider_locked_by_id = ( new \WP_AI_Client_Prompt_Builder( $registry, $prompt ) )
			->with_history( $history )
			->using_provider( self::COLLISION_PROVIDER_B_ID )
			->using_model_preference( self::COLLISION_SHARED_MODEL )
			->generate_text_result();

		$provider_locked_by_class = ( new \WP_AI_Client_Prompt_Builder( $registry, $prompt ) )
			->with_history( $history )
			->using_provider( AiClientSurface_CollisionProviderB::class )
			->using_model_preference( self::COLLISION_SHARED_MODEL )
			->generate_text_result();

		$fallback = ( new \WP_AI_Client_Prompt_Builder( $registry, $prompt ) )
			->with_history( $history )
			->using_model_preference(
				'missing-' . self::slug_piece( $case->fork( 'missing' ), 'model' ),
				array( self::COLLISION_PROVIDER_B_ID, self::COLLISION_B_ONLY_MODEL )
			)
			->generate_text_result();

		$discovery_order = ( new \WP_AI_Client_Prompt_Builder( $registry, $prompt ) )
			->with_history( $history )
			->generate_text_result();

		$reversed_model_only = ( new \WP_AI_Client_Prompt_Builder( $reversed_registry, $prompt ) )
			->with_history( $history )
			->using_model_preference( self::COLLISION_SHARED_MODEL )
			->generate_text_result();

		self::collect_failure(
			$failures,
			self::result_selects_provider_model(
				$model_only,
				self::COLLISION_PROVIDER_A_ID,
				self::COLLISION_SHARED_MODEL
			),
			'model-only preference for shared model preserves first registered provider',
			array( 'selection' => self::describe_ai_result_selection( $model_only ) )
		);

		self::collect_failure(
			$failures,
			self::result_selects_provider_model(
				$provider_tuple,
				self::COLLISION_PROVIDER_B_ID,
				self::COLLISION_SHARED_MODEL
			),
			'provider/model tuple preference overrides shared model collision order',
			array( 'selection' => self::describe_ai_result_selection( $provider_tuple ) )
		);

		self::collect_failure(
			$failures,
			self::result_selects_provider_model(
				$model_instance_preference,
				self::COLLISION_PROVIDER_B_ID,
				self::COLLISION_SHARED_MODEL
			),
			'model-instance preference carries its provider/model pair through collisions',
			array( 'selection' => self::describe_ai_result_selection( $model_instance_preference ) )
		);

		self::collect_failure(
			$failures,
			self::result_selects_provider_model(
				$provider_locked_by_id,
				self::COLLISION_PROVIDER_B_ID,
				self::COLLISION_SHARED_MODEL
			),
			'provider lock by ID narrows shared model lookup to that provider',
			array( 'selection' => self::describe_ai_result_selection( $provider_locked_by_id ) )
		);

		self::collect_failure(
			$failures,
			self::result_selects_provider_model(
				$provider_locked_by_class,
				self::COLLISION_PROVIDER_B_ID,
				self::COLLISION_SHARED_MODEL
			),
			'provider lock by class narrows shared model lookup to that provider',
			array( 'selection' => self::describe_ai_result_selection( $provider_locked_by_class ) )
		);

		self::collect_failure(
			$failures,
			self::result_selects_provider_model(
				$fallback,
				self::COLLISION_PROVIDER_B_ID,
				self::COLLISION_B_ONLY_MODEL
			),
			'missing model preference falls through to the first matching later preference',
			array( 'selection' => self::describe_ai_result_selection( $fallback ) )
		);

		self::collect_failure(
			$failures,
			self::result_selects_provider_model(
				$discovery_order,
				self::COLLISION_PROVIDER_A_ID,
				self::COLLISION_SHARED_MODEL
			),
			'no model preference falls back to first matching provider/model discovery order',
			array( 'selection' => self::describe_ai_result_selection( $discovery_order ) )
		);

		self::collect_failure(
			$failures,
			self::result_selects_provider_model(
				$reversed_model_only,
				self::COLLISION_PROVIDER_B_ID,
				self::COLLISION_SHARED_MODEL
			),
			'reversed registration order changes the model-only collision winner',
			array( 'selection' => self::describe_ai_result_selection( $reversed_model_only ) )
		);

		return self::result(
			$ctx,
			'ai-client.model-selection-preferences-and-provider-collisions',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 8 ) )
		);
	}

	private static function check_prompt_builder_and_events( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$registry = new \WordPress\AiClient\Providers\ProviderRegistry();
		$registry->registerProvider( AiClientSurface_FakeProvider::class );

		$events = array(
			'before' => array(),
			'after'  => array(),
		);

		$before_listener = static function ( object $event ) use ( &$events ): void {
			$events['before'][] = get_class( $event );
		};
		$after_listener  = static function ( object $event ) use ( &$events ): void {
			$events['after'][] = get_class( $event );
		};

		\WordPress\AiClient\AiClient::setEventDispatcher( new \WP_AI_Client_Event_Dispatcher() );
		\add_action( 'wp_ai_client_before_generate_result', $before_listener );
		\add_action( 'wp_ai_client_after_generate_result', $after_listener );

		try {
			$prompt = 'Summarize ' . self::safe_text( $ctx->fork( 'prompt' ), 6, 20 );
			$result = ( new \WP_AI_Client_Prompt_Builder( $registry, $prompt ) )
				->using_provider( AiClientSurface_FakeProvider::class )
				->using_model_preference( self::MODEL_ID )
				->using_temperature( self::small_float( $ctx->fork( 'temperature' ), 0, 20 ) )
				->using_max_tokens( $ctx->fork( 'tokens' )->int( 8, 64 ) )
				->using_system_instruction( 'System ' . self::safe_text( $ctx->fork( 'system' ), 4, 16 ) )
				->as_json_response( self::simple_json_schema() )
				->generate_text_result();
		} finally {
			\remove_action( 'wp_ai_client_before_generate_result', $before_listener );
			\remove_action( 'wp_ai_client_after_generate_result', $after_listener );
		}

		self::collect_failure(
			$failures,
			$result instanceof \WordPress\AiClient\Results\DTO\GenerativeAiResult
				&& 1 === $result->getCandidateCount()
				&& str_starts_with( $result->toText(), 'component-fuzz:' ),
			'WordPress prompt builder proxies snake_case configuration and generates with fake model',
			array(
				'result' => $result instanceof \WordPress\AiClient\Results\DTO\GenerativeAiResult
					? $result->toArray()
					: self::describe_value( $result ),
			)
		);

		self::collect_failure(
			$failures,
			array( 'WordPress\AiClient\Events\BeforeGenerateResultEvent' ) === $events['before']
				&& array( 'WordPress\AiClient\Events\AfterGenerateResultEvent' ) === $events['after'],
			'WordPress event dispatcher maps SDK events to expected action hooks once',
			array( 'events' => $events )
		);

		$bad_builder       = new \WP_AI_Client_Prompt_Builder( $registry, '' );
		$chain_after_error = $bad_builder->with_text( 'ignored after constructor error' );
		$supported_after_error = $bad_builder->is_supported_for_text_generation();
		$error_after_error     = $bad_builder->generate_text_result();

		self::collect_failure(
			$failures,
			$chain_after_error === $bad_builder
				&& false === $supported_after_error
				&& \is_wp_error( $error_after_error )
				&& 'prompt_invalid_argument' === $error_after_error->get_error_code(),
			'WP prompt builder preserves error state for fluent and terminal calls',
			array(
				'supported' => $supported_after_error,
				'error'     => self::describe_error( $error_after_error ),
			)
		);

		$disable_filter = static fn(): bool => false;
		\add_filter( 'wp_supports_ai', $disable_filter );
		try {
			$prevented = ( new \WP_AI_Client_Prompt_Builder( $registry, 'Prevented prompt' ) )
				->generate_text_result();
		} finally {
			\remove_filter( 'wp_supports_ai', $disable_filter );
		}

		self::collect_failure(
			$failures,
			\is_wp_error( $prevented ) && 'prompt_prevented' === $prevented->get_error_code(),
			'wp_supports_ai filter prevents generation before model execution',
			array( 'prevented' => self::describe_error( $prevented ) )
		);

		return self::result(
			$ctx,
			'ai-client.prompt-builder-events-and-wp-error-state',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 8 ) )
		);
	}

	private static function check_ability_resolver_integration( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$case     = $ctx->fork( 'ability' );
		$category = self::slug_piece( $case, 'ai-cat' );
		$name     = 'cfuzz-ai/' . self::slug_piece( $case->fork( 'ability-name' ), 'resolve' );
		$value    = 'value-' . self::slug_piece( $case->fork( 'value' ), 'v' );

		self::ensure_init_fired();
		self::reset_ability_registries();

		$input_schema = array(
			'type'       => 'object',
			'required'   => array( 'value' ),
			'properties' => array(
				'value' => array( 'type' => 'string' ),
			),
		);
		$output_schema = array(
			'type'       => 'object',
			'required'   => array( 'echo', 'ability' ),
			'properties' => array(
				'echo'    => array( 'type' => 'string' ),
				'ability' => array( 'type' => 'string' ),
			),
		);

		$category_action = static function () use ( $category ): void {
			\wp_register_ability_category(
				$category,
				array(
					'label'       => 'AI Client Fuzz',
					'description' => 'AI client fuzzer abilities.',
				)
			);
		};
		$ability_action  = static function () use ( $name, $category, $input_schema, $output_schema ): void {
			\wp_register_ability(
				$name,
				array(
					'label'               => 'Resolve AI function call',
					'description'         => 'Echoes deterministic fuzzer input.',
					'category'            => $category,
					'input_schema'        => $input_schema,
					'output_schema'       => $output_schema,
					'execute_callback'    => static fn( array $input ): array => array(
						'echo'    => $input['value'],
						'ability' => $name,
					),
					'permission_callback' => static fn(): bool => true,
					'meta'                => array(
						'annotations' => array(
							'readonly'   => true,
							'idempotent' => true,
						),
					),
				)
			);
		};

		\add_action( 'wp_abilities_api_categories_init', $category_action );
		\add_action( 'wp_abilities_api_init', $ability_action );
		try {
			\WP_Ability_Categories_Registry::get_instance();
			\WP_Abilities_Registry::get_instance();
		} finally {
			\remove_action( 'wp_abilities_api_categories_init', $category_action );
			\remove_action( 'wp_abilities_api_init', $ability_action );
		}

		$ability       = \wp_get_ability( $name );
		$function_name = \WP_AI_Client_Ability_Function_Resolver::ability_name_to_function_name( $name );
		$call          = new \WordPress\AiClient\Tools\DTO\FunctionCall(
			'call-' . $case->iteration(),
			$function_name,
			array( 'value' => $value )
		);
		$resolver      = new \WP_AI_Client_Ability_Function_Resolver( $ability );
		$response      = $resolver->execute_ability( $call );
		$denied        = ( new \WP_AI_Client_Ability_Function_Resolver( 'cfuzz-ai/other' ) )->execute_ability( $call );
		$message       = new \WordPress\AiClient\Messages\DTO\ModelMessage(
			array( new \WordPress\AiClient\Messages\DTO\MessagePart( $call ) )
		);
		$responses     = $resolver->execute_abilities( $message );

		self::collect_failure(
			$failures,
			$ability instanceof \WP_Ability
				&& $function_name === 'wpab__' . str_replace( '/', '__', $name )
				&& \WP_AI_Client_Ability_Function_Resolver::function_name_to_ability_name( $function_name ) === $name,
			'ability registration and function-name mapping are reversible',
			array(
				'ability'      => self::describe_ability( $ability ),
				'functionName' => $function_name,
			)
		);

		self::collect_failure(
			$failures,
			$resolver->is_ability_call( $call )
				&& array(
					'echo'    => $value,
					'ability' => $name,
				) === $response->getResponse(),
			'allowed resolver executes registered ability and returns exact response payload',
			array( 'response' => $response->toArray() )
		);

		self::collect_failure(
			$failures,
			'ability_not_allowed' === ( $denied->getResponse()['code'] ?? null ),
			'resolver rejects ability calls outside the allowed set',
			array( 'denied' => $denied->toArray() )
		);

		$response_parts = $responses->getParts();
		self::collect_failure(
			$failures,
			$resolver->has_ability_calls( $message )
				&& $responses->getRole()->isUser()
				&& 1 === count( $response_parts )
				&& $response_parts[0]->getFunctionResponse() instanceof \WordPress\AiClient\Tools\DTO\FunctionResponse
				&& $response_parts[0]->getFunctionResponse()->getResponse()['echo'] === $value,
			'resolver converts model function calls to user function response messages',
			array( 'responses' => $responses->toArray() )
		);

		$builder = new \WP_AI_Client_Prompt_Builder( new \WordPress\AiClient\Providers\ProviderRegistry(), 'Use ability' );
		$same    = $builder->using_abilities( $name );
		$config  = self::get_wrapped_prompt_builder_model_config( $builder );
		$tools   = $config instanceof \WordPress\AiClient\Providers\Models\DTO\ModelConfig
			? $config->getFunctionDeclarations()
			: null;
		$tool    = is_array( $tools ) ? ( $tools[0] ?? null ) : null;

		self::collect_failure(
			$failures,
			$same === $builder
				&& $tool instanceof \WordPress\AiClient\Tools\DTO\FunctionDeclaration
				&& $function_name === $tool->getName()
				&& $input_schema === $tool->getParameters(),
			'WP prompt builder converts registered abilities into function declarations',
			array(
				'tool' => $tool instanceof \WordPress\AiClient\Tools\DTO\FunctionDeclaration ? $tool->toArray() : $tool,
			)
		);

		return self::result(
			$ctx,
			'ai-client.ability-function-resolver-and-prompt-builder-integration',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 8 ) )
		);
	}

	private static function check_cache_and_dispatcher_adapters( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$cache    = new \WP_AI_Client_Cache();
		$key_a    = 'ai-client-' . dechex( $ctx->seed() ) . '-a';
		$key_b    = 'ai-client-' . dechex( $ctx->seed() ) . '-b';
		$value_a  = array(
			'seed'  => $ctx->seed(),
			'text'  => self::safe_text( $ctx->fork( 'cache-a' ), 4, 20 ),
			'false' => false,
		);
		$value_b  = false;

		\WordPress\AiClient\AiClient::setCache( $cache );

		$missing = $cache->get( $key_a, 'fallback' );
		$set     = $cache->setMultiple(
			array(
				$key_a => $value_a,
				$key_b => $value_b,
			)
		);
		$multi   = $cache->getMultiple( array( $key_a, $key_b, 'missing-' . $key_a ), 'fallback' );
		$has_a   = $cache->has( $key_a );
		$delete  = $cache->deleteMultiple( array( $key_a, $key_b ) );
		$after   = $cache->getMultiple( array( $key_a, $key_b ), 'fallback' );

		self::collect_failure(
			$failures,
			$cache === \WordPress\AiClient\AiClient::getCache()
				&& 'fallback' === $missing
				&& true === $set
				&& true === $has_a
				&& self::same_value( $value_a, $multi[ $key_a ] ?? null )
				&& false === ( $multi[ $key_b ] ?? null )
				&& 'fallback' === ( $multi[ 'missing-' . $key_a ] ?? null )
				&& true === $delete
				&& array( $key_a => 'fallback', $key_b => 'fallback' ) === $after,
			'WP AI cache adapter preserves PSR-16 get/set/delete semantics including stored false',
			array(
				'missing' => $missing,
				'multi'   => $multi,
				'after'   => $after,
			)
		);

		$clear_key = $key_a . '-clear';
		$cache->set( $clear_key, 'clear-value' );
		$clear = $cache->clear();
		$clear_supported = function_exists( 'wp_cache_supports' ) && \wp_cache_supports( 'flush_group' );
		$after_clear = $cache->get( $clear_key, 'fallback' );
		$cache->delete( $clear_key );

		self::collect_failure(
			$failures,
			( $clear_supported && true === $clear && 'fallback' === $after_clear )
				|| ( ! $clear_supported && false === $clear && 'clear-value' === $after_clear ),
			'WP AI cache clear mirrors object-cache flush_group support',
			array(
				'supported'  => $clear_supported,
				'clear'      => $clear,
				'afterClear' => $after_clear,
			)
		);

		$dispatcher = new \WP_AI_Client_Event_Dispatcher();
		$seen       = array();
		$listener   = static function ( object $event ) use ( &$seen ): void {
			$seen[] = $event;
		};
		$event      = new \stdClass();

		\WordPress\AiClient\AiClient::setEventDispatcher( $dispatcher );
		\add_action( 'wp_ai_client_std_class', $listener );
		try {
			$dispatched = $dispatcher->dispatch( $event );
		} finally {
			\remove_action( 'wp_ai_client_std_class', $listener );
		}

		self::collect_failure(
			$failures,
			$dispatcher === \WordPress\AiClient\AiClient::getEventDispatcher()
				&& $dispatched === $event
				&& array( $event ) === $seen,
			'WP AI event dispatcher returns the original event and fires the derived hook',
			array(
				'seenCount'   => count( $seen ),
				'eventClass'  => get_class( $event ),
				'returnedSame' => $dispatched === $event,
			)
		);

		return self::result(
			$ctx,
			'ai-client.cache-and-event-dispatcher-adapters-are-in-memory',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 8 ) )
		);
	}

	private static function check_http_transport_bridge( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$case     = $ctx->fork( 'http-transport' );
		$factory  = new \WordPress\AiClientDependencies\Nyholm\Psr7\Factory\Psr17Factory();

		$candidates        = \WP_AI_Client_Discovery_Strategy::getCandidates(
			\WordPress\AiClientDependencies\Psr\Http\Client\ClientInterface::class
		);
		$candidate_factory = $candidates[0]['class'] ?? null;
		$discovered_client = is_callable( $candidate_factory ) ? $candidate_factory() : null;
		$client            = $discovered_client instanceof \WP_AI_Client_HTTP_Client
			? $discovered_client
			: new \WP_AI_Client_HTTP_Client( $factory, $factory );

		self::collect_failure(
			$failures,
			$discovered_client instanceof \WP_AI_Client_HTTP_Client,
			'WP AI client discovery strategy creates the WordPress HTTP client adapter',
			array(
				'candidateCount' => count( $candidates ),
				'clientClass'    => is_object( $discovered_client ) ? get_class( $discovered_client ) : gettype( $discovered_client ),
			)
		);

		$discovery_state = self::snapshot_class_discovery_state();

		$discovery_url       = 'https://' . self::domain( $case->fork( 'discovery-domain' ) ) . '/discovered';
		$discovery_body      = (string) wp_json_encode( array( 'ping' => self::safe_text( $case->fork( 'discovery-body' ), 3, 10 ) ) );
		$discovery_reply     = (string) wp_json_encode( array( 'ok' => true, 'source' => 'discovery' ) );
		$discovery_seen      = array();
		$discovery_response  = null;
		$discovery_throwable = null;
		$discovery_request   = new \WordPress\AiClient\Providers\Http\DTO\Request(
			\WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum::from( 'POST' ),
			$discovery_url,
			array( 'Content-Type' => array( 'application/json' ) ),
			$discovery_body
		);
		$discovery_filter    = static function ( $_pre, array $args, string $url ) use ( &$discovery_seen, $discovery_reply ) {
			$discovery_seen[] = array(
				'url'  => $url,
				'args' => $args,
			);

			return self::wp_http_response(
				202,
				'Accepted',
				array( 'Content-Type' => 'application/json' ),
				$discovery_reply
			);
		};

		\add_filter( 'pre_http_request', $discovery_filter, 10, 3 );
		try {
			self::restore_class_discovery_state(
				array(
					'strategies' => array( \WP_AI_Client_Discovery_Strategy::class ),
					'cache'      => array(),
				)
			);
			$discovery_transporter = new \WordPress\AiClient\Providers\Http\HttpTransporter( null, $factory, $factory );
			$discovery_response    = $discovery_transporter->send( $discovery_request );
		} catch ( \Throwable $e ) {
			$discovery_throwable = $e;
		} finally {
			\remove_filter( 'pre_http_request', $discovery_filter, 10 );
			self::restore_class_discovery_state( $discovery_state );
		}

		$discovery_args = $discovery_seen[0]['args'] ?? array();
		$restored_state = self::snapshot_class_discovery_state();
		self::collect_failure(
			$failures,
			null === $discovery_throwable
				&& 1 === count( $discovery_seen )
				&& $discovery_url === ( $discovery_seen[0]['url'] ?? null )
				&& 'POST' === ( $discovery_args['method'] ?? null )
				&& $discovery_body === ( $discovery_args['body'] ?? null )
				&& $discovery_response instanceof \WordPress\AiClient\Providers\Http\DTO\Response
				&& 202 === $discovery_response->getStatusCode()
				&& $discovery_reply === $discovery_response->getBody()
				&& $discovery_state === $restored_state,
			'HTTPlug discovery finds the WordPress HTTP client for HttpTransporter',
			array(
				'throwable' => $discovery_throwable ? self::describe_throwable( $discovery_throwable ) : null,
				'request'   => $discovery_seen[0] ?? null,
				'response'  => $discovery_response instanceof \WordPress\AiClient\Providers\Http\DTO\Response
					? $discovery_response->toArray()
					: self::describe_value( $discovery_response ),
				'stateRestored' => $discovery_state === $restored_state,
			)
		);

		$direct_url          = 'https://' . self::domain( $case->fork( 'direct-domain' ) ) . '/v1/responses';
		$direct_body         = (string) wp_json_encode(
			array(
				'prompt' => self::safe_text( $case->fork( 'direct-body' ), 6, 24 ),
				'seed'   => $ctx->seed(),
			)
		);
		$direct_protocol     = $case->bool() ? '1.0' : '1.1';
		$direct_timeout      = self::small_float( $case->fork( 'direct-timeout' ), 10, 90 );
		$direct_redirection  = $case->fork( 'direct-redirection' )->int( 0, 4 );
		$direct_response     = null;
		$direct_throwable    = null;
		$direct_requests     = array();
		$direct_header_extra = 'branch-' . self::slug_piece( $case->fork( 'direct-header' ), 'branch' );
		$direct_reply_body   = (string) wp_json_encode(
			array(
				'id'     => 'resp-' . self::slug_piece( $case->fork( 'direct-response' ), 'resp' ),
				'output' => array( 'text' => self::safe_text( $case->fork( 'direct-output' ), 4, 18 ) ),
			)
		);
		$direct_request      = $factory->createRequest( 'PATCH', $direct_url )
			->withProtocolVersion( $direct_protocol )
			->withHeader( 'Content-Type', 'application/json' )
			->withHeader( 'X-Fuzz', array( 'seed-' . $ctx->seed(), $direct_header_extra ) )
			->withHeader( 'X-Trace', 'trace-' . self::slug_piece( $case->fork( 'direct-trace' ), 'trace' ) )
			->withBody( $factory->createStream( $direct_body ) );
		$direct_options      = \WordPress\AiClient\Providers\Http\DTO\RequestOptions::fromArray(
			array(
				'timeout'      => $direct_timeout,
				'maxRedirects' => $direct_redirection,
			)
		);
		$direct_filter       = static function ( $_pre, array $args, string $url ) use ( &$direct_requests, $direct_reply_body ) {
			$direct_requests[] = array(
				'url'  => $url,
				'args' => $args,
			);

			return self::wp_http_response(
				207,
				'Multi-Status',
				array(
					'Content-Type' => 'application/json',
					'X-Reply'      => array( 'reply-a', 'reply-b' ),
				),
				$direct_reply_body
			);
		};

		\add_filter( 'pre_http_request', $direct_filter, 10, 3 );
		try {
			$direct_response = $client->sendRequestWithOptions( $direct_request, $direct_options );
		} catch ( \Throwable $e ) {
			$direct_throwable = $e;
		} finally {
			\remove_filter( 'pre_http_request', $direct_filter, 10 );
		}

		$direct_args = $direct_requests[0]['args'] ?? array();
		self::collect_failure(
			$failures,
			null === $direct_throwable
				&& 1 === count( $direct_requests )
				&& $direct_url === ( $direct_requests[0]['url'] ?? null )
				&& 'PATCH' === ( $direct_args['method'] ?? null )
				&& $direct_protocol === ( $direct_args['httpversion'] ?? null )
				&& true === ( $direct_args['blocking'] ?? null )
				&& $direct_body === ( $direct_args['body'] ?? null )
				&& $direct_timeout === ( $direct_args['timeout'] ?? null )
				&& $direct_redirection === ( $direct_args['redirection'] ?? null )
				&& 'application/json' === ( $direct_args['headers']['Content-Type'] ?? null )
				&& 'seed-' . $ctx->seed() . ', ' . $direct_header_extra === ( $direct_args['headers']['X-Fuzz'] ?? null ),
			'WP HTTP client maps PSR-7 request fields and transport options to WordPress HTTP args',
			array(
				'throwable' => $direct_throwable ? self::describe_throwable( $direct_throwable ) : null,
				'request'   => $direct_requests[0] ?? null,
			)
		);

		self::collect_failure(
			$failures,
			$direct_response instanceof \WordPress\AiClientDependencies\Psr\Http\Message\ResponseInterface
				&& 207 === $direct_response->getStatusCode()
				&& 'Multi-Status' === $direct_response->getReasonPhrase()
				&& 'application/json' === $direct_response->getHeaderLine( 'Content-Type' )
				&& 'reply-a, reply-b' === $direct_response->getHeaderLine( 'X-Reply' )
				&& $direct_reply_body === (string) $direct_response->getBody(),
			'WP HTTP client maps WordPress HTTP responses back to PSR-7 responses',
			array(
				'status'  => $direct_response instanceof \WordPress\AiClientDependencies\Psr\Http\Message\ResponseInterface
					? $direct_response->getStatusCode()
					: null,
				'headers' => $direct_response instanceof \WordPress\AiClientDependencies\Psr\Http\Message\ResponseInterface
					? $direct_response->getHeaders()
					: null,
				'body'    => $direct_response instanceof \WordPress\AiClientDependencies\Psr\Http\Message\ResponseInterface
					? (string) $direct_response->getBody()
					: null,
			)
		);

		$body_edge_cases    = array(
			array( 'label' => 'empty', 'body' => '' ),
			array( 'label' => 'zero', 'body' => '0' ),
			array( 'label' => 'scalar-json-false', 'body' => 'false' ),
			array( 'label' => 'scalar-json-string', 'body' => '"scalar"' ),
		);
		$body_edge_failures = array();
		$body_edge_seen     = array();

		foreach ( $body_edge_cases as $edge_case ) {
			$edge_seen      = array();
			$edge_response  = null;
			$edge_throwable = null;
			$edge_url       = 'https://' . self::domain( $case->fork( 'body-edge-' . $edge_case['label'] ) ) . '/edge';
			$edge_request   = $factory->createRequest( 'GET', $edge_url );
			$edge_filter    = static function ( $_pre, array $args, string $url ) use ( &$edge_seen, $edge_case ) {
				$edge_seen[] = array(
					'url'  => $url,
					'args' => $args,
				);

				return self::wp_http_response(
					200,
					'OK',
					array( 'Content-Type' => 'application/json' ),
					$edge_case['body']
				);
			};

			\add_filter( 'pre_http_request', $edge_filter, 10, 3 );
			try {
				$edge_response = $client->sendRequestWithOptions( $edge_request, $direct_options );
			} catch ( \Throwable $e ) {
				$edge_throwable = $e;
			} finally {
				\remove_filter( 'pre_http_request', $edge_filter, 10 );
			}

			$edge_actual       = $edge_response instanceof \WordPress\AiClientDependencies\Psr\Http\Message\ResponseInterface
				? (string) $edge_response->getBody()
				: null;
			$body_edge_seen[] = array(
				'label'     => $edge_case['label'],
				'body'      => self::describe_value( $edge_case['body'] ),
				'actual'    => self::describe_value( $edge_actual ),
				'request'   => $edge_seen[0] ?? null,
				'throwable' => $edge_throwable ? self::describe_throwable( $edge_throwable ) : null,
			);

			if (
				null !== $edge_throwable ||
				1 !== count( $edge_seen ) ||
				! $edge_response instanceof \WordPress\AiClientDependencies\Psr\Http\Message\ResponseInterface ||
				$edge_case['body'] !== $edge_actual
			) {
				$body_edge_failures[] = end( $body_edge_seen );
			}
		}

		self::collect_failure(
			$failures,
			array() === $body_edge_failures,
			'WP HTTP client preserves exact empty and scalar response bodies',
			array(
				'cases'    => $body_edge_seen,
				'failures' => $body_edge_failures,
			)
		);

		$error_requests  = array();
		$error_throwable = null;
		$error_filter    = static function ( $_pre, array $args, string $url ) use ( &$error_requests ) {
			$error_requests[] = array(
				'url'  => $url,
				'args' => $args,
			);

			return new \WP_Error( '429', 'Synthetic AI transport timeout' );
		};

		\add_filter( 'pre_http_request', $error_filter, 10, 3 );
		try {
			$client->sendRequestWithOptions( $direct_request, $direct_options );
		} catch ( \Throwable $e ) {
			$error_throwable = $e;
		} finally {
			\remove_filter( 'pre_http_request', $error_filter, 10 );
		}

		self::collect_failure(
			$failures,
			1 === count( $error_requests )
				&& $error_throwable instanceof \WordPress\AiClient\Providers\Http\Exception\NetworkException
				&& 429 === $error_throwable->getCode()
				&& str_contains( $error_throwable->getMessage(), $direct_url )
				&& str_contains( $error_throwable->getMessage(), 'Synthetic AI transport timeout' ),
			'WP HTTP client converts WP_Error failures to SDK NetworkException',
			array(
				'throwable' => $error_throwable ? self::describe_throwable( $error_throwable ) : null,
				'requests'  => $error_requests,
			)
		);

		$request_options   = \WordPress\AiClient\Providers\Http\DTO\RequestOptions::fromArray(
			array(
				'timeout'        => 1.2,
				'connectTimeout' => 0.7,
				'maxRedirects'   => 5,
			)
		);
		$parameter_timeout = self::small_float( $case->fork( 'parameter-timeout' ), 20, 80 );
		$parameter_options = \WordPress\AiClient\Providers\Http\DTO\RequestOptions::fromArray(
			array(
				'timeout'      => $parameter_timeout,
				'maxRedirects' => 0,
			)
		);
		$transport_url     = 'https://' . self::domain( $case->fork( 'transport-domain' ) ) . '/chat/completions';
		$transport_body    = (string) wp_json_encode(
			array(
				'model' => self::MODEL_ID,
				'input' => self::safe_text( $case->fork( 'transport-body' ), 5, 18 ),
			)
		);
		$transport_request = new \WordPress\AiClient\Providers\Http\DTO\Request(
			\WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum::from( 'POST' ),
			$transport_url,
			array(
				'Content-Type'  => array( 'application/json' ),
				'Authorization' => array( 'Bearer local-' . self::slug_piece( $case->fork( 'token' ), 'token' ) ),
				'X-Client'      => array( 'ai-client-fuzz', 'transport' ),
			),
			$transport_body,
			$request_options
		);
		$transport_reply   = (string) wp_json_encode(
			array(
				'choices' => array(
					array(
						'message' => array(
							'content' => self::safe_text( $case->fork( 'transport-reply' ), 6, 18 ),
						),
					),
				),
			)
		);
		$transporter       = new \WordPress\AiClient\Providers\Http\HttpTransporter( $client, $factory, $factory );
		$capture_client    = new AiClientSurface_CapturingHttpClient( $factory, $factory, 202, 'Accepted', $transport_reply );
		$capture_transport = new \WordPress\AiClient\Providers\Http\HttpTransporter( $capture_client, $factory, $factory );
		$capture_response  = null;
		$capture_throwable = null;
		try {
			$capture_response = $capture_transport->send( $transport_request, $parameter_options );
		} catch ( \Throwable $e ) {
			$capture_throwable = $e;
		}

		$captured_options = $capture_client->last_options();
		$captured_request = $capture_client->last_request();
		self::collect_failure(
			$failures,
			null === $capture_throwable
				&& $capture_response instanceof \WordPress\AiClient\Providers\Http\DTO\Response
				&& 202 === $capture_response->getStatusCode()
				&& 0 === $capture_client->send_request_count()
				&& 1 === $capture_client->send_request_with_options_count()
				&& $captured_request instanceof \WordPress\AiClientDependencies\Psr\Http\Message\RequestInterface
				&& 'POST' === $captured_request->getMethod()
				&& $transport_url === (string) $captured_request->getUri()
				&& $captured_options instanceof \WordPress\AiClient\Providers\Http\DTO\RequestOptions
				&& $parameter_timeout === $captured_options->getTimeout()
				&& 0.7 === $captured_options->getConnectTimeout()
				&& 0 === $captured_options->getMaxRedirects(),
			'HttpTransporter preserves request-only SDK options while parameter options override matching fields',
			array(
				'throwable' => $capture_throwable ? self::describe_throwable( $capture_throwable ) : null,
				'request'   => $captured_request instanceof \WordPress\AiClientDependencies\Psr\Http\Message\RequestInterface
					? array(
						'method' => $captured_request->getMethod(),
						'uri'    => (string) $captured_request->getUri(),
					)
					: self::describe_value( $captured_request ),
				'options'   => $captured_options instanceof \WordPress\AiClient\Providers\Http\DTO\RequestOptions
					? $captured_options->toArray()
					: self::describe_value( $captured_options ),
				'counts'    => array(
					'sendRequest'            => $capture_client->send_request_count(),
					'sendRequestWithOptions' => $capture_client->send_request_with_options_count(),
				),
			)
		);

		$transport_seen    = array();
		$transport_response = null;
		$transport_throwable = null;
		$transport_filter  = static function ( $_pre, array $args, string $url ) use ( &$transport_seen, $transport_reply ) {
			$transport_seen[] = array(
				'url'  => $url,
				'args' => $args,
			);

			return self::wp_http_response(
				201,
				'Created',
				array(
					'Content-Type' => 'application/json',
					'X-SDK'        => array( 'mapped', 'response' ),
				),
				$transport_reply
			);
		};

		\add_filter( 'pre_http_request', $transport_filter, 10, 3 );
		try {
			$transport_response = $transporter->send( $transport_request, $parameter_options );
		} catch ( \Throwable $e ) {
			$transport_throwable = $e;
		} finally {
			\remove_filter( 'pre_http_request', $transport_filter, 10 );
		}

		$transport_args = $transport_seen[0]['args'] ?? array();
		self::collect_failure(
			$failures,
			null === $transport_throwable
				&& 1 === count( $transport_seen )
				&& $transport_url === ( $transport_seen[0]['url'] ?? null )
				&& 'POST' === ( $transport_args['method'] ?? null )
				&& '1.1' === ( $transport_args['httpversion'] ?? null )
				&& $transport_body === ( $transport_args['body'] ?? null )
				&& $parameter_timeout === ( $transport_args['timeout'] ?? null )
				&& 0 === ( $transport_args['redirection'] ?? null )
				&& ! array_key_exists( 'connect_timeout', $transport_args )
				&& ! array_key_exists( 'connectTimeout', $transport_args )
				&& 'application/json' === ( $transport_args['headers']['Content-Type'] ?? null )
				&& 'ai-client-fuzz, transport' === ( $transport_args['headers']['X-Client'] ?? null ),
			'HttpTransporter maps SDK requests through WP HTTP and lets parameter options override request options',
			array(
				'throwable' => $transport_throwable ? self::describe_throwable( $transport_throwable ) : null,
				'request'   => $transport_seen[0] ?? null,
			)
		);

		self::collect_failure(
			$failures,
			$transport_response instanceof \WordPress\AiClient\Providers\Http\DTO\Response
				&& 201 === $transport_response->getStatusCode()
				&& $transport_reply === $transport_response->getBody()
				&& array( 'application/json' ) === self::header_values( $transport_response->getHeaders(), 'Content-Type' )
				&& array( 'mapped', 'response' ) === self::header_values( $transport_response->getHeaders(), 'X-SDK' )
				&& is_array( $transport_response->getData() )
				&& isset( $transport_response->getData()['choices'][0]['message']['content'] ),
			'HttpTransporter maps PSR-7 responses back to SDK response DTOs',
			array(
				'response' => $transport_response instanceof \WordPress\AiClient\Providers\Http\DTO\Response
					? $transport_response->toArray()
					: self::describe_value( $transport_response ),
			)
		);

		$transport_error_seen      = array();
		$transport_error_throwable = null;
		$transport_error_filter    = static function ( $_pre, array $args, string $url ) use ( &$transport_error_seen ) {
			$transport_error_seen[] = array(
				'url'  => $url,
				'args' => $args,
			);

			return new \WP_Error( '503', 'Synthetic SDK transport outage' );
		};

		\add_filter( 'pre_http_request', $transport_error_filter, 10, 3 );
		try {
			$transporter->send( $transport_request, $parameter_options );
		} catch ( \Throwable $e ) {
			$transport_error_throwable = $e;
		} finally {
			\remove_filter( 'pre_http_request', $transport_error_filter, 10 );
		}

		$transport_error_request           = null;
		$transport_error_request_throwable = null;
		if ( $transport_error_throwable instanceof \WordPress\AiClient\Providers\Http\Exception\NetworkException ) {
			try {
				$transport_error_request = $transport_error_throwable->getRequest();
			} catch ( \Throwable $e ) {
				$transport_error_request_throwable = $e;
			}
		}
		$transport_error_previous = $transport_error_throwable instanceof \Throwable
			? $transport_error_throwable->getPrevious()
			: null;

		self::collect_failure(
			$failures,
			1 === count( $transport_error_seen )
				&& $transport_error_throwable instanceof \WordPress\AiClient\Providers\Http\Exception\NetworkException
				&& str_contains( $transport_error_throwable->getMessage(), $transport_url )
				&& str_contains( $transport_error_throwable->getMessage(), 'Synthetic SDK transport outage' )
				&& $transport_error_previous instanceof \WordPress\AiClient\Providers\Http\Exception\NetworkException
				&& 503 === $transport_error_previous->getCode()
				&& null === $transport_error_request_throwable
				&& $transport_error_request instanceof \WordPress\AiClient\Providers\Http\DTO\Request
				&& $transport_url === $transport_error_request->getUri()
				&& 'POST' === $transport_error_request->getMethod()->value,
			'HttpTransporter wraps WP HTTP adapter NetworkException failures with request context',
			array(
				'throwable'        => $transport_error_throwable ? self::describe_throwable( $transport_error_throwable ) : null,
				'previous'         => $transport_error_previous ? self::describe_throwable( $transport_error_previous ) : null,
				'requestThrowable' => $transport_error_request_throwable ? self::describe_throwable( $transport_error_request_throwable ) : null,
				'request'          => $transport_error_request instanceof \WordPress\AiClient\Providers\Http\DTO\Request
					? $transport_error_request->toArray()
					: self::describe_value( $transport_error_request ),
				'requests'         => $transport_error_seen,
			)
		);

		return self::result(
			$ctx,
			'ai-client.wp-http-transport-mapping-and-errors',
			array() === $failures,
			array(
				'failures' => array_slice( $failures, 0, 8 ),
				'requests' => array(
					'discovery'      => count( $discovery_seen ),
					'direct'         => count( $direct_requests ),
					'bodyEdges'      => count( $body_edge_seen ),
					'error'          => count( $error_requests ),
					'optionsCapture' => $capture_client->send_request_with_options_count(),
					'transport'      => count( $transport_seen ),
					'transportError' => count( $transport_error_seen ),
				),
			)
		);
	}

	private static function dto_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$text_part       = self::message_part_text_array( $ctx->fork( 'text-part' ) );
		$file_part       = self::message_part_file_array( $ctx->fork( 'file-part' ) );
		$function_call   = self::function_call_array( $ctx->fork( 'function-call' ) );
		$function_resp   = self::function_response_array( $ctx->fork( 'function-response' ) );
		$model_message   = self::model_message_array( $ctx->fork( 'model-message' ) );
		$user_message    = self::user_message_array( $ctx->fork( 'user-message' ) );
		$provider        = self::provider_metadata_array( $ctx->fork( 'provider' ) );
		$supported       = self::supported_option_array();
		$model           = self::model_metadata_array( $ctx->fork( 'model' ), array( $supported ) );
		$token_usage     = self::token_usage_array( $ctx->fork( 'token-usage' ) );
		$candidate       = self::candidate_array( $model_message );
		$result          = self::result_array( $ctx->fork( 'result' ), $candidate, $token_usage, $provider, $model );
		$operation_state = $ctx->bool() ? 'succeeded' : 'processing';
		$operation       = 'succeeded' === $operation_state
			? array(
				'id'     => 'op-' . self::slug_piece( $ctx->fork( 'operation' ), 'op' ),
				'state'  => 'succeeded',
				'result' => $result,
			)
			: array(
				'id'    => 'op-' . self::slug_piece( $ctx->fork( 'operation' ), 'op' ),
				'state' => $operation_state,
			);

		return array(
			self::dto_case(
				'file.inline',
				\WordPress\AiClient\Files\DTO\File::class,
				self::file_array( $ctx->fork( 'file' ) ),
				array( 'fileType', 'mimeType', 'base64Data' ),
				array( array( 'fileType', 'mimeType', 'base64Data' ) )
			),
			self::dto_case(
				'message-part.text',
				\WordPress\AiClient\Messages\DTO\MessagePart::class,
				$text_part,
				array( 'channel', 'type', 'text' ),
				array( array( 'type', 'text' ) )
			),
			self::dto_case(
				'message-part.file',
				\WordPress\AiClient\Messages\DTO\MessagePart::class,
				$file_part,
				array( 'channel', 'type', 'file' ),
				array( array( 'type', 'file' ) )
			),
			self::dto_case(
				'message.user',
				\WordPress\AiClient\Messages\DTO\Message::class,
				$user_message,
				array( 'role', 'parts' ),
				array( array( 'role', 'parts' ) )
			),
			self::dto_case(
				'message.model',
				\WordPress\AiClient\Messages\DTO\Message::class,
				$model_message,
				array( 'role', 'parts' ),
				array( array( 'role', 'parts' ) )
			),
			self::dto_case(
				'function-declaration',
				\WordPress\AiClient\Tools\DTO\FunctionDeclaration::class,
				self::function_declaration_array( $ctx->fork( 'function-declaration' ) ),
				array( 'name', 'description', 'parameters' ),
				array( array( 'name', 'description' ) )
			),
			self::dto_case(
				'function-call',
				\WordPress\AiClient\Tools\DTO\FunctionCall::class,
				$function_call,
				array( 'id', 'name', 'args' ),
				array( array( 'id' ), array( 'name' ) )
			),
			self::dto_case(
				'function-response',
				\WordPress\AiClient\Tools\DTO\FunctionResponse::class,
				$function_resp,
				array( 'id', 'name', 'response' ),
				array( array( 'response', 'id' ), array( 'response', 'name' ) )
			),
			self::dto_case(
				'web-search',
				\WordPress\AiClient\Tools\DTO\WebSearch::class,
				self::web_search_array( $ctx->fork( 'web-search' ) ),
				array( 'allowedDomains', 'disallowedDomains' ),
				array()
			),
			self::dto_case(
				'model-config',
				\WordPress\AiClient\Providers\Models\DTO\ModelConfig::class,
				self::model_config_array( $ctx->fork( 'model-config' ) ),
				array( 'outputModalities', 'systemInstruction', 'outputMimeType', 'outputSchema' ),
				array()
			),
			self::dto_case(
				'supported-option',
				\WordPress\AiClient\Providers\Models\DTO\SupportedOption::class,
				$supported,
				array( 'name', 'supportedValues' ),
				array( array( 'name' ) )
			),
			self::dto_case(
				'required-option',
				\WordPress\AiClient\Providers\Models\DTO\RequiredOption::class,
				self::required_option_array(),
				array( 'name', 'value' ),
				array( array( 'name', 'value' ) )
			),
			self::dto_case(
				'model-metadata',
				\WordPress\AiClient\Providers\Models\DTO\ModelMetadata::class,
				$model,
				array( 'id', 'name', 'supportedCapabilities', 'supportedOptions' ),
				array( array( 'id', 'name', 'supportedCapabilities', 'supportedOptions' ) )
			),
			self::dto_case(
				'model-requirements',
				\WordPress\AiClient\Providers\Models\DTO\ModelRequirements::class,
				self::model_requirements_array(),
				array( 'requiredCapabilities', 'requiredOptions' ),
				array( array( 'requiredCapabilities', 'requiredOptions' ) )
			),
			self::dto_case(
				'provider-metadata',
				\WordPress\AiClient\Providers\DTO\ProviderMetadata::class,
				$provider,
				array( 'id', 'name', 'description', 'type', 'credentialsUrl', 'authenticationMethod', 'logoPath' ),
				array( array( 'id', 'name', 'type' ) )
			),
			self::dto_case(
				'provider-models-metadata',
				\WordPress\AiClient\Providers\DTO\ProviderModelsMetadata::class,
				array(
					'provider' => $provider,
					'models'   => array( $model ),
				),
				array( 'provider', 'models' ),
				array( array( 'provider', 'models' ) )
			),
			self::dto_case(
				'token-usage',
				\WordPress\AiClient\Results\DTO\TokenUsage::class,
				$token_usage,
				array( 'promptTokens', 'completionTokens', 'totalTokens', 'thoughtTokens' ),
				array( array( 'promptTokens', 'completionTokens', 'totalTokens' ) )
			),
			self::dto_case(
				'candidate',
				\WordPress\AiClient\Results\DTO\Candidate::class,
				$candidate,
				array( 'message', 'finishReason' ),
				array( array( 'message', 'finishReason' ) )
			),
			self::dto_case(
				'generative-result',
				\WordPress\AiClient\Results\DTO\GenerativeAiResult::class,
				$result,
				array( 'id', 'candidates', 'tokenUsage', 'providerMetadata', 'modelMetadata', 'additionalData' ),
				array( array( 'id', 'candidates', 'tokenUsage', 'providerMetadata', 'modelMetadata' ) )
			),
			self::dto_case(
				'operation',
				\WordPress\AiClient\Operations\DTO\GenerativeAiOperation::class,
				$operation,
				array_keys( $operation ),
				'succeeded' === $operation_state
					? array( array( 'id', 'state', 'result' ) )
					: array( array( 'id', 'state' ) )
			),
			self::dto_case(
				'request-options',
				\WordPress\AiClient\Providers\Http\DTO\RequestOptions::class,
				self::request_options_array( $ctx->fork( 'request-options' ) ),
				array( 'timeout', 'connectTimeout', 'maxRedirects' ),
				array()
			),
			self::dto_case(
				'request',
				\WordPress\AiClient\Providers\Http\DTO\Request::class,
				self::request_array( $ctx->fork( 'request' ) ),
				array( 'method', 'uri', 'headers', 'body', 'options' ),
				array( array( 'method', 'uri', 'headers' ) )
			),
			self::dto_case(
				'response',
				\WordPress\AiClient\Providers\Http\DTO\Response::class,
				self::response_array( $ctx->fork( 'response' ) ),
				array( 'statusCode', 'headers', 'body' ),
				array( array( 'statusCode', 'headers' ) )
			),
			self::dto_case(
				'api-key-auth',
				\WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication::class,
				array( 'apiKey' => 'key-' . self::slug_piece( $ctx->fork( 'api-key' ), 'key' ) ),
				array( 'apiKey' ),
				array( array( 'apiKey' ) )
			),
		);
	}

	private static function dto_case(
		string $label,
		string $class,
		array $input,
		array $canonical_keys,
		array $schema_required_sets
	): array {
		return array(
			'label'              => $label,
			'class'              => $class,
			'input'              => $input,
			'canonicalKeys'      => $canonical_keys,
			'schemaRequiredSets' => $schema_required_sets,
		);
	}

	private static function file_array( \ComponentFuzz\FuzzContext $ctx ): array {
		return array(
			'fileType'   => 'inline',
			'mimeType'   => 'text/plain',
			'base64Data' => base64_encode( self::safe_text( $ctx, 4, 24 ) ),
		);
	}

	private static function message_part_text_array( \ComponentFuzz\FuzzContext $ctx ): array {
		return array(
			'channel'          => $ctx->bool() ? 'content' : 'thought',
			'type'             => 'text',
			'text'             => self::safe_text( $ctx, 3, 28 ),
			'thoughtSignature' => 'sig-' . self::slug_piece( $ctx->fork( 'sig' ), 'sig' ),
		);
	}

	private static function message_part_file_array( \ComponentFuzz\FuzzContext $ctx ): array {
		return array(
			'channel' => 'content',
			'type'    => 'file',
			'file'    => self::file_array( $ctx->fork( 'part-file' ) ),
		);
	}

	private static function message_part_function_call_array( \ComponentFuzz\FuzzContext $ctx ): array {
		return array(
			'channel'      => 'content',
			'type'         => 'function_call',
			'functionCall' => self::function_call_array( $ctx ),
		);
	}

	private static function message_part_function_response_array( \ComponentFuzz\FuzzContext $ctx ): array {
		return array(
			'channel'          => 'content',
			'type'             => 'function_response',
			'functionResponse' => self::function_response_array( $ctx ),
		);
	}

	private static function user_message_array( \ComponentFuzz\FuzzContext $ctx ): array {
		return array(
			'role'  => 'user',
			'parts' => array(
				self::message_part_text_array( $ctx->fork( 'text' ) ),
				self::message_part_file_array( $ctx->fork( 'file' ) ),
				self::message_part_function_response_array( $ctx->fork( 'function-response' ) ),
			),
		);
	}

	private static function model_message_array( \ComponentFuzz\FuzzContext $ctx ): array {
		return array(
			'role'  => 'model',
			'parts' => array(
				self::message_part_text_array( $ctx->fork( 'text' ) ),
				self::message_part_function_call_array( $ctx->fork( 'function-call' ) ),
			),
		);
	}

	private static function function_declaration_array( \ComponentFuzz\FuzzContext $ctx ): array {
		return array(
			'name'        => 'tool_' . self::slug_piece( $ctx, 'tool' ),
			'description' => 'Tool ' . self::safe_text( $ctx->fork( 'description' ), 4, 20 ),
			'parameters'  => self::simple_json_schema(),
		);
	}

	private static function function_call_array( \ComponentFuzz\FuzzContext $ctx ): array {
		return array(
			'id'   => 'call-' . self::slug_piece( $ctx, 'id' ),
			'name' => 'tool_' . self::slug_piece( $ctx->fork( 'name' ), 'tool' ),
			'args' => array(
				'value' => self::safe_text( $ctx->fork( 'args' ), 2, 16 ),
				'count' => $ctx->int( 0, 10 ),
			),
		);
	}

	private static function function_response_array( \ComponentFuzz\FuzzContext $ctx ): array {
		return array(
			'id'       => 'call-' . self::slug_piece( $ctx, 'id' ),
			'name'     => 'tool_' . self::slug_piece( $ctx->fork( 'name' ), 'tool' ),
			'response' => array(
				'ok'    => true,
				'value' => self::safe_text( $ctx->fork( 'response' ), 2, 16 ),
			),
		);
	}

	private static function web_search_array( \ComponentFuzz\FuzzContext $ctx ): array {
		return array(
			'allowedDomains'    => array( self::domain( $ctx->fork( 'allow-a' ) ), self::domain( $ctx->fork( 'allow-b' ) ) ),
			'disallowedDomains' => array( self::domain( $ctx->fork( 'deny' ) ) ),
		);
	}

	private static function model_config_array( \ComponentFuzz\FuzzContext $ctx ): array {
		$orientation = $ctx->choice( array( 'square', 'landscape', 'portrait' ) );
		$aspect     = array(
			'square'    => '1:1',
			'landscape' => '16:9',
			'portrait'  => '9:16',
		)[ $orientation ];

		return array(
			'outputModalities'       => array( 'text' ),
			'systemInstruction'      => 'System ' . self::safe_text( $ctx->fork( 'system' ), 4, 18 ),
			'candidateCount'         => $ctx->int( 1, 3 ),
			'maxTokens'              => $ctx->int( 8, 96 ),
			'temperature'            => self::small_float( $ctx->fork( 'temperature' ), 0, 20 ),
			'topP'                   => self::small_float( $ctx->fork( 'top-p' ), 1, 10 ),
			'topK'                   => $ctx->int( 1, 64 ),
			'stopSequences'          => array( 'STOP-' . self::slug_piece( $ctx->fork( 'stop' ), 'stop' ) ),
			'presencePenalty'        => self::small_float( $ctx->fork( 'presence' ), -10, 10 ),
			'frequencyPenalty'       => self::small_float( $ctx->fork( 'frequency' ), -10, 10 ),
			'logprobs'               => $ctx->bool(),
			'topLogprobs'            => $ctx->int( 1, 5 ),
			'functionDeclarations'   => array( self::function_declaration_array( $ctx->fork( 'tool' ) ) ),
			'webSearch'              => self::web_search_array( $ctx->fork( 'web' ) ),
			'outputFileType'         => 'inline',
			'outputMimeType'         => 'application/json',
			'outputSchema'           => self::simple_json_schema(),
			'outputMediaOrientation' => $orientation,
			'outputMediaAspectRatio' => $aspect,
			'outputSpeechVoice'      => 'voice-' . self::slug_piece( $ctx->fork( 'voice' ), 'voice' ),
			'customOptions'          => array(
				'trace' => 'trace-' . self::slug_piece( $ctx->fork( 'trace' ), 'trace' ),
			),
		);
	}

	private static function supported_option_array(): array {
		return array(
			'name'            => 'outputModalities',
			'supportedValues' => array( array( 'text' ), array( 'image' ) ),
		);
	}

	private static function required_option_array(): array {
		return array(
			'name'  => 'outputModalities',
			'value' => array( 'text' ),
		);
	}

	private static function model_requirements_array(): array {
		return array(
			'requiredCapabilities' => array( 'text_generation' ),
			'requiredOptions'      => array( self::required_option_array() ),
		);
	}

	private static function provider_metadata_array( \ComponentFuzz\FuzzContext $ctx ): array {
		return array(
			'id'                   => 'provider-' . self::slug_piece( $ctx, 'provider' ),
			'name'                 => 'Provider ' . self::safe_text( $ctx->fork( 'name' ), 4, 18 ),
			'description'          => 'Description ' . self::safe_text( $ctx->fork( 'description' ), 4, 20 ),
			'type'                 => 'server',
			'credentialsUrl'       => 'https://example.test/credentials',
			'authenticationMethod' => 'api_key',
			'logoPath'             => '/tmp/component-fuzz-logo.svg',
		);
	}

	private static function model_metadata_array( \ComponentFuzz\FuzzContext $ctx, array $supported_options ): array {
		return array(
			'id'                    => 'model-' . self::slug_piece( $ctx, 'model' ),
			'name'                  => 'Model ' . self::safe_text( $ctx->fork( 'name' ), 4, 18 ),
			'supportedCapabilities' => array( 'text_generation', 'chat_history' ),
			'supportedOptions'      => $supported_options,
		);
	}

	private static function token_usage_array( \ComponentFuzz\FuzzContext $ctx ): array {
		$prompt     = $ctx->int( 1, 128 );
		$completion = $ctx->int( 1, 128 );
		$thought    = $ctx->int( 0, $completion );

		return array(
			'promptTokens'     => $prompt,
			'completionTokens' => $completion,
			'totalTokens'      => $prompt + $completion,
			'thoughtTokens'    => $thought,
		);
	}

	private static function candidate_array( array $model_message ): array {
		return array(
			'message'      => $model_message,
			'finishReason' => 'stop',
		);
	}

	private static function result_array(
		\ComponentFuzz\FuzzContext $ctx,
		array $candidate,
		array $token_usage,
		array $provider,
		array $model
	): array {
		return array(
			'id'               => 'result-' . self::slug_piece( $ctx, 'result' ),
			'candidates'       => array( $candidate ),
			'tokenUsage'       => $token_usage,
			'providerMetadata' => $provider,
			'modelMetadata'    => $model,
			'additionalData'   => array(
				'seed' => $ctx->seed(),
				'note' => self::safe_text( $ctx->fork( 'note' ), 2, 18 ),
			),
		);
	}

	private static function request_options_array( \ComponentFuzz\FuzzContext $ctx ): array {
		return array(
			'timeout'        => self::small_float( $ctx->fork( 'timeout' ), 1, 100 ),
			'connectTimeout' => self::small_float( $ctx->fork( 'connect-timeout' ), 1, 50 ),
			'maxRedirects'   => $ctx->int( 0, 5 ),
		);
	}

	private static function request_array( \ComponentFuzz\FuzzContext $ctx ): array {
		return array(
			'method'  => 'POST',
			'uri'     => 'https://example.test/ai/' . self::slug_piece( $ctx, 'request' ),
			'headers' => array(
				'Content-Type' => array( 'application/json' ),
				'X-Fuzz'       => array( 'seed-' . $ctx->seed() ),
			),
			'body'    => wp_json_encode(
				array(
					'value' => self::safe_text( $ctx->fork( 'body' ), 2, 18 ),
				)
			),
			'options' => self::request_options_array( $ctx->fork( 'options' ) ),
		);
	}

	private static function response_array( \ComponentFuzz\FuzzContext $ctx ): array {
		return array(
			'statusCode' => $ctx->choice( array( 200, 201, 202, 400, 429, 500 ) ),
			'headers'    => array(
				'Content-Type' => array( 'application/json' ),
				'X-Fuzz'       => array( 'response-' . $ctx->seed() ),
			),
			'body'       => wp_json_encode(
				array(
					'ok'    => true,
					'value' => self::safe_text( $ctx->fork( 'body' ), 2, 18 ),
				)
			),
		);
	}

	private static function model_config( \ComponentFuzz\FuzzContext $ctx ): \WordPress\AiClient\Providers\Models\DTO\ModelConfig {
		return \WordPress\AiClient\Providers\Models\DTO\ModelConfig::fromArray(
			array(
				'outputModalities'  => array( 'text' ),
				'systemInstruction' => 'System ' . self::safe_text( $ctx->fork( 'config' ), 3, 16 ),
				'maxTokens'         => $ctx->fork( 'max-tokens' )->int( 8, 64 ),
				'temperature'       => self::small_float( $ctx->fork( 'config-temp' ), 0, 20 ),
			)
		);
	}

	private static function simple_json_schema(): array {
		return array(
			'type'       => 'object',
			'required'   => array( 'value' ),
			'properties' => array(
				'value' => array( 'type' => 'string' ),
			),
		);
	}

	private static function schema_declares_required_set( array $schema, array $required_set ): bool {
		if ( isset( $schema['required'] ) && is_array( $schema['required'] ) ) {
			$required = array_map( 'strval', $schema['required'] );
			if ( array() === array_diff( $required_set, $required ) ) {
				return true;
			}
		}

		foreach ( array( 'oneOf', 'anyOf', 'allOf' ) as $key ) {
			if ( ! isset( $schema[ $key ] ) || ! is_array( $schema[ $key ] ) ) {
				continue;
			}
			foreach ( $schema[ $key ] as $child ) {
				if ( is_array( $child ) && self::schema_declares_required_set( $child, $required_set ) ) {
					return true;
				}
			}
		}

		if ( isset( $schema['properties'] ) && is_array( $schema['properties'] ) ) {
			foreach ( $schema['properties'] as $child ) {
				if ( is_array( $child ) && self::schema_declares_required_set( $child, $required_set ) ) {
					return true;
				}
			}
		}

		if ( isset( $schema['items'] ) && is_array( $schema['items'] ) ) {
			return self::schema_declares_required_set( $schema['items'], $required_set );
		}

		return false;
	}

	private static function get_wrapped_prompt_builder_model_config( \WP_AI_Client_Prompt_Builder $builder ) {
		$builder_property = new \ReflectionProperty( \WP_AI_Client_Prompt_Builder::class, 'builder' );
		$sdk_builder      = $builder_property->getValue( $builder );

		$config_property = new \ReflectionProperty( $sdk_builder, 'modelConfig' );
		return $config_property->getValue( $sdk_builder );
	}

	private static function ensure_init_fired(): void {
		if ( ! isset( $GLOBALS['wp_actions'] ) || ! is_array( $GLOBALS['wp_actions'] ) ) {
			$GLOBALS['wp_actions'] = array();
		}
		$GLOBALS['wp_actions']['init'] = max( 1, (int) ( $GLOBALS['wp_actions']['init'] ?? 0 ) );
	}

	private static function reset_ability_registries(): void {
		self::set_static_property( 'WP_Abilities_Registry', 'instance', null );
		self::set_static_property( 'WP_Ability_Categories_Registry', 'instance', null );
	}

	private static function snapshot_state(): array {
		$abilities  = self::get_static_property( 'WP_Abilities_Registry', 'instance' );
		$categories = self::get_static_property( 'WP_Ability_Categories_Registry', 'instance' );

		return array(
			'globals'              => self::snapshot_globals(
				array(
					'wp_filter',
					'wp_filters',
					'wp_actions',
					'wp_current_filter',
					'wp_object_cache',
				)
			),
			'aiDefaultRegistry'    => self::get_static_property( \WordPress\AiClient\AiClient::class, 'defaultRegistry' ),
			'aiCache'              => self::get_static_property( \WordPress\AiClient\AiClient::class, 'cache' ),
			'aiEventDispatcher'    => self::get_static_property( \WordPress\AiClient\AiClient::class, 'eventDispatcher' ),
			'fakeProvider'         => AiClientSurface_FakeProvider::snapshot(),
			'abilities'            => $abilities,
			'registeredAbilities'  => $abilities instanceof \WP_Abilities_Registry
				? self::get_object_property( $abilities, 'registered_abilities' )
				: null,
			'categories'           => $categories,
			'registeredCategories' => $categories instanceof \WP_Ability_Categories_Registry
				? self::get_object_property( $categories, 'registered_categories' )
				: null,
		);
	}

	private static function restore_state( array $snapshot ): void {
		self::restore_globals( $snapshot['globals'] );
		self::set_static_property( \WordPress\AiClient\AiClient::class, 'defaultRegistry', $snapshot['aiDefaultRegistry'] );
		self::set_static_property( \WordPress\AiClient\AiClient::class, 'cache', $snapshot['aiCache'] );
		self::set_static_property(
			\WordPress\AiClient\AiClient::class,
			'eventDispatcher',
			$snapshot['aiEventDispatcher']
		);
		AiClientSurface_FakeProvider::restore( $snapshot['fakeProvider'] );

		if ( $snapshot['abilities'] instanceof \WP_Abilities_Registry ) {
			self::set_object_property( $snapshot['abilities'], 'registered_abilities', $snapshot['registeredAbilities'] );
			self::set_static_property( 'WP_Abilities_Registry', 'instance', $snapshot['abilities'] );
		} else {
			self::set_static_property( 'WP_Abilities_Registry', 'instance', null );
		}

		if ( $snapshot['categories'] instanceof \WP_Ability_Categories_Registry ) {
			self::set_object_property( $snapshot['categories'], 'registered_categories', $snapshot['registeredCategories'] );
			self::set_static_property( 'WP_Ability_Categories_Registry', 'instance', $snapshot['categories'] );
		} else {
			self::set_static_property( 'WP_Ability_Categories_Registry', 'instance', null );
		}
	}

	private static function snapshot_class_discovery_state(): array {
		$class = \WordPress\AiClientDependencies\Http\Discovery\ClassDiscovery::class;

		return array(
			'strategies' => self::get_static_property( $class, 'strategies' ),
			'cache'      => self::get_static_property( $class, 'cache' ),
		);
	}

	private static function restore_class_discovery_state( array $snapshot ): void {
		$class = \WordPress\AiClientDependencies\Http\Discovery\ClassDiscovery::class;

		self::set_static_property( $class, 'strategies', $snapshot['strategies'] ?? array() );
		self::set_static_property( $class, 'cache', $snapshot['cache'] ?? array() );
	}

	private static function snapshot_globals( array $names ): array {
		$snapshot = array();
		foreach ( $names as $name ) {
			$snapshot[ $name ] = array(
				'exists' => array_key_exists( $name, $GLOBALS ),
				'value'  => array_key_exists( $name, $GLOBALS ) ? $GLOBALS[ $name ] : null,
			);
		}
		return $snapshot;
	}

	private static function restore_globals( array $snapshot ): void {
		foreach ( $snapshot as $name => $entry ) {
			if ( $entry['exists'] ) {
				$GLOBALS[ $name ] = $entry['value'];
			} else {
				unset( $GLOBALS[ $name ] );
			}
		}
	}

	private static function get_static_property( string $class, string $property ) {
		if ( ! class_exists( $class ) ) {
			return null;
		}
		$reflection = new \ReflectionProperty( $class, $property );
		return $reflection->getValue();
	}

	private static function set_static_property( string $class, string $property, $value ): void {
		if ( ! class_exists( $class ) ) {
			return;
		}
		$reflection = new \ReflectionProperty( $class, $property );
		$reflection->setValue( null, $value );
	}

	private static function get_object_property( object $object, string $property ) {
		$reflection = new \ReflectionProperty( $object, $property );
		return $reflection->getValue( $object );
	}

	private static function set_object_property( object $object, string $property, $value ): void {
		$reflection = new \ReflectionProperty( $object, $property );
		$reflection->setValue( $object, $value );
	}

	private static function throws( callable $callback ): bool {
		try {
			$callback();
		} catch ( \Throwable $e ) {
			return true;
		}

		return false;
	}

	private static function same_value( $expected, $actual ): bool {
		return $expected === $actual;
	}

	private static function collect_failure( array &$failures, bool $condition, string $label, array $details ): void {
		if ( $condition ) {
			return;
		}

		$failures[] = array(
			'label'   => $label,
			'details' => self::describe_value( $details ),
		);
	}

	private static function result( \ComponentFuzz\FuzzContext $ctx, string $invariant, bool $ok, array $data = array() ): array {
		return $ok ? $ctx->pass( $invariant, $data ) : $ctx->fail( $invariant, $data );
	}

	private static function describe_ability( $ability ): array {
		if ( ! $ability instanceof \WP_Ability ) {
			return array( 'value' => self::describe_value( $ability ) );
		}

		return array(
			'name'        => $ability->get_name(),
			'description' => $ability->get_description(),
			'category'    => $ability->get_category(),
			'inputSchema' => $ability->get_input_schema(),
		);
	}

	private static function describe_error( $value ): array {
		if ( ! \is_wp_error( $value ) ) {
			return array( 'value' => self::describe_value( $value ) );
		}

		return array(
			'code'    => $value->get_error_code(),
			'message' => $value->get_error_message(),
			'data'    => $value->get_error_data(),
		);
	}

	private static function wp_http_response( int $status_code, string $message, array $headers, string $body ): array {
		return array(
			'headers'  => $headers,
			'body'     => $body,
			'response' => array(
				'code'    => $status_code,
				'message' => $message,
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	private static function header_values( array $headers, string $name ): ?array {
		foreach ( $headers as $header_name => $values ) {
			if ( 0 !== strcasecmp( (string) $header_name, $name ) ) {
				continue;
			}

			if ( is_array( $values ) ) {
				return array_map( 'strval', array_values( $values ) );
			}

			return array_map( 'trim', explode( ',', (string) $values ) );
		}

		return null;
	}

	private static function result_selects_provider_model( $result, string $provider_id, string $model_id ): bool {
		return $result instanceof \WordPress\AiClient\Results\DTO\GenerativeAiResult
			&& $provider_id === $result->getProviderMetadata()->getId()
			&& $model_id === $result->getModelMetadata()->getId();
	}

	private static function describe_ai_result_selection( $result ): array {
		if ( \is_wp_error( $result ) ) {
			return self::describe_error( $result );
		}

		if ( ! $result instanceof \WordPress\AiClient\Results\DTO\GenerativeAiResult ) {
			return array( 'value' => self::describe_value( $result ) );
		}

		return array(
			'providerId' => $result->getProviderMetadata()->getId(),
			'modelId'    => $result->getModelMetadata()->getId(),
			'text'       => $result->toText(),
		);
	}

	private static function describe_value( $value ) {
		if ( is_object( $value ) ) {
			return '[object ' . get_class( $value ) . ']';
		}
		if ( is_array( $value ) ) {
			return $value;
		}
		return $value;
	}

	private static function describe_throwable( \Throwable $e ): array {
		return array(
			'class'   => get_class( $e ),
			'message' => $e->getMessage(),
			'file'    => $e->getFile(),
			'line'    => $e->getLine(),
		);
	}

	private static function safe_text( \ComponentFuzz\FuzzContext $ctx, int $min, int $max ): string {
		$text = $ctx->ascii( $min, $max );
		$text = preg_replace( '/[^A-Za-z0-9 _.,:-]/', '_', $text );
		$text = trim( (string) $text );
		return '' === $text ? 'component-fuzz' : $text;
	}

	private static function slug_piece( \ComponentFuzz\FuzzContext $ctx, string $prefix ): string {
		$raw = strtolower( $prefix . '-' . $ctx->identifier( 3, 12 ) . '-' . dechex( $ctx->seed() & 0xffff ) );
		$raw = preg_replace( '/[^a-z0-9]+/', '-', $raw );
		$raw = trim( (string) $raw, '-' );
		$raw = preg_replace( '/-+/', '-', $raw );
		return '' === $raw ? $prefix . '-' . dechex( $ctx->seed() & 0xffff ) : substr( $raw, 0, 40 );
	}

	private static function domain( \ComponentFuzz\FuzzContext $ctx ): string {
		return self::slug_piece( $ctx, 'domain' ) . '.example.test';
	}

	private static function small_float( \ComponentFuzz\FuzzContext $ctx, int $min_tenths, int $max_tenths ): float {
		return $ctx->int( $min_tenths, $max_tenths ) / 10;
	}
}

final class AiClientSurface_CapturingHttpClient implements
	\WordPress\AiClientDependencies\Psr\Http\Client\ClientInterface,
	\WordPress\AiClient\Providers\Http\Contracts\ClientWithOptionsInterface {

	private \WordPress\AiClientDependencies\Psr\Http\Message\ResponseFactoryInterface $response_factory;
	private \WordPress\AiClientDependencies\Psr\Http\Message\StreamFactoryInterface $stream_factory;
	private ?\WordPress\AiClientDependencies\Psr\Http\Message\RequestInterface $last_request = null;
	private ?\WordPress\AiClient\Providers\Http\DTO\RequestOptions $last_options = null;
	private int $send_request_count = 0;
	private int $send_request_with_options_count = 0;
	private int $status_code;
	private string $reason_phrase;
	private string $body;

	public function __construct(
		\WordPress\AiClientDependencies\Psr\Http\Message\ResponseFactoryInterface $response_factory,
		\WordPress\AiClientDependencies\Psr\Http\Message\StreamFactoryInterface $stream_factory,
		int $status_code,
		string $reason_phrase,
		string $body
	) {
		$this->response_factory = $response_factory;
		$this->stream_factory   = $stream_factory;
		$this->status_code      = $status_code;
		$this->reason_phrase    = $reason_phrase;
		$this->body             = $body;
	}

	public function sendRequest(
		\WordPress\AiClientDependencies\Psr\Http\Message\RequestInterface $request
	): \WordPress\AiClientDependencies\Psr\Http\Message\ResponseInterface {
		$this->last_request = $request;
		$this->last_options = null;
		++$this->send_request_count;

		return $this->response();
	}

	public function sendRequestWithOptions(
		\WordPress\AiClientDependencies\Psr\Http\Message\RequestInterface $request,
		\WordPress\AiClient\Providers\Http\DTO\RequestOptions $options
	): \WordPress\AiClientDependencies\Psr\Http\Message\ResponseInterface {
		$this->last_request = $request;
		$this->last_options = $options;
		++$this->send_request_with_options_count;

		return $this->response();
	}

	public function last_request(): ?\WordPress\AiClientDependencies\Psr\Http\Message\RequestInterface {
		return $this->last_request;
	}

	public function last_options(): ?\WordPress\AiClient\Providers\Http\DTO\RequestOptions {
		return $this->last_options;
	}

	public function send_request_count(): int {
		return $this->send_request_count;
	}

	public function send_request_with_options_count(): int {
		return $this->send_request_with_options_count;
	}

	private function response(): \WordPress\AiClientDependencies\Psr\Http\Message\ResponseInterface {
		$response = $this->response_factory->createResponse( $this->status_code, $this->reason_phrase )
			->withHeader( 'Content-Type', 'application/json' )
			->withHeader( 'X-Capture', 'options' );

		if ( '' !== $this->body ) {
			$response = $response->withBody( $this->stream_factory->createStream( $this->body ) );
		}

		return $response;
	}
}

final class AiClientSurface_FakeProvider implements \WordPress\AiClient\Providers\Contracts\ProviderInterface {
	private static bool $configured = true;

	public static function reset(): void {
		self::$configured = true;
	}

	public static function snapshot(): array {
		return array( 'configured' => self::$configured );
	}

	public static function restore( array $snapshot ): void {
		self::$configured = (bool) ( $snapshot['configured'] ?? true );
	}

	public static function set_configured( bool $configured ): void {
		self::$configured = $configured;
	}

	public static function metadata(): \WordPress\AiClient\Providers\DTO\ProviderMetadata {
		return new \WordPress\AiClient\Providers\DTO\ProviderMetadata(
			AiClientSurface::PROVIDER_ID,
			'Component Fuzz AI',
			\WordPress\AiClient\Providers\Enums\ProviderTypeEnum::server()
		);
	}

	public static function modelMetadata(): \WordPress\AiClient\Providers\Models\DTO\ModelMetadata {
		$options = array_map(
			static fn( \WordPress\AiClient\Providers\Models\Enums\OptionEnum $option ) =>
				new \WordPress\AiClient\Providers\Models\DTO\SupportedOption( $option ),
			\WordPress\AiClient\Providers\Models\Enums\OptionEnum::cases()
		);

		return new \WordPress\AiClient\Providers\Models\DTO\ModelMetadata(
			AiClientSurface::MODEL_ID,
			'Component Fuzz Text',
			array(
				\WordPress\AiClient\Providers\Models\Enums\CapabilityEnum::textGeneration(),
				\WordPress\AiClient\Providers\Models\Enums\CapabilityEnum::chatHistory(),
			),
			$options
		);
	}

	public static function model(
		string $modelId,
		?\WordPress\AiClient\Providers\Models\DTO\ModelConfig $modelConfig = null
	): \WordPress\AiClient\Providers\Models\Contracts\ModelInterface {
		if ( AiClientSurface::MODEL_ID !== $modelId ) {
			throw new \WordPress\AiClient\Common\Exception\InvalidArgumentException( 'Unknown fake model: ' . $modelId );
		}

		return new AiClientSurface_FakeTextModel( $modelConfig ?? new \WordPress\AiClient\Providers\Models\DTO\ModelConfig() );
	}

	public static function availability(): \WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface {
		return new AiClientSurface_FakeAvailability( self::$configured );
	}

	public static function modelMetadataDirectory(): \WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface {
		return new AiClientSurface_FakeModelMetadataDirectory();
	}
}

final class AiClientSurface_FakeAvailability implements \WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface {
	private bool $configured;

	public function __construct( bool $configured ) {
		$this->configured = $configured;
	}

	public function isConfigured(): bool {
		return $this->configured;
	}
}

final class AiClientSurface_FakeModelMetadataDirectory implements \WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface {
	public function listModelMetadata(): array {
		return array( AiClientSurface_FakeProvider::modelMetadata() );
	}

	public function hasModelMetadata( string $modelId ): bool {
		return AiClientSurface::MODEL_ID === $modelId;
	}

	public function getModelMetadata( string $modelId ): \WordPress\AiClient\Providers\Models\DTO\ModelMetadata {
		if ( ! $this->hasModelMetadata( $modelId ) ) {
			throw new \WordPress\AiClient\Common\Exception\InvalidArgumentException( 'Unknown fake model: ' . $modelId );
		}

		return AiClientSurface_FakeProvider::modelMetadata();
	}
}

final class AiClientSurface_FakeTextModel implements
	\WordPress\AiClient\Providers\Models\Contracts\ModelInterface,
	\WordPress\AiClient\Providers\Models\TextGeneration\Contracts\TextGenerationModelInterface {

	private \WordPress\AiClient\Providers\Models\DTO\ModelConfig $config;

	public function __construct( \WordPress\AiClient\Providers\Models\DTO\ModelConfig $config ) {
		$this->config = $config;
	}

	public function metadata(): \WordPress\AiClient\Providers\Models\DTO\ModelMetadata {
		return AiClientSurface_FakeProvider::modelMetadata();
	}

	public function providerMetadata(): \WordPress\AiClient\Providers\DTO\ProviderMetadata {
		return AiClientSurface_FakeProvider::metadata();
	}

	public function setConfig( \WordPress\AiClient\Providers\Models\DTO\ModelConfig $config ): void {
		$this->config = $config;
	}

	public function getConfig(): \WordPress\AiClient\Providers\Models\DTO\ModelConfig {
		return $this->config;
	}

	public function generateTextResult( array $prompt ): \WordPress\AiClient\Results\DTO\GenerativeAiResult {
		$last_text = '';
		foreach ( $prompt as $message ) {
			if ( ! $message instanceof \WordPress\AiClient\Messages\DTO\Message ) {
				continue;
			}
			foreach ( $message->getParts() as $part ) {
				if ( null !== $part->getText() ) {
					$last_text = $part->getText();
				}
			}
		}

		$text = 'component-fuzz:' . count( $prompt ) . ':' . $last_text;

		return new \WordPress\AiClient\Results\DTO\GenerativeAiResult(
			'fake-result-' . substr( sha1( $text ), 0, 12 ),
			array(
				new \WordPress\AiClient\Results\DTO\Candidate(
					new \WordPress\AiClient\Messages\DTO\ModelMessage(
						array( new \WordPress\AiClient\Messages\DTO\MessagePart( $text ) )
					),
					\WordPress\AiClient\Results\Enums\FinishReasonEnum::stop()
				),
			),
			new \WordPress\AiClient\Results\DTO\TokenUsage( strlen( $last_text ), strlen( $text ), strlen( $last_text ) + strlen( $text ) ),
			$this->providerMetadata(),
			$this->metadata(),
			array( 'promptCount' => count( $prompt ) )
		);
	}
}

final class AiClientSurface_CollisionProviderA implements \WordPress\AiClient\Providers\Contracts\ProviderInterface {
	public static function metadata(): \WordPress\AiClient\Providers\DTO\ProviderMetadata {
		return new \WordPress\AiClient\Providers\DTO\ProviderMetadata(
			AiClientSurface::COLLISION_PROVIDER_A_ID,
			'Component Fuzz Collision A',
			\WordPress\AiClient\Providers\Enums\ProviderTypeEnum::server()
		);
	}

	public static function model(
		string $modelId,
		?\WordPress\AiClient\Providers\Models\DTO\ModelConfig $modelConfig = null
	): \WordPress\AiClient\Providers\Models\Contracts\ModelInterface {
		if ( ! in_array( $modelId, array( AiClientSurface::COLLISION_SHARED_MODEL, AiClientSurface::COLLISION_A_ONLY_MODEL ), true ) ) {
			throw new \WordPress\AiClient\Common\Exception\InvalidArgumentException( 'Unknown collision model A: ' . $modelId );
		}

		return new AiClientSurface_CollisionTextModel(
			self::metadata(),
			$modelId,
			$modelConfig ?? new \WordPress\AiClient\Providers\Models\DTO\ModelConfig()
		);
	}

	public static function availability(): \WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface {
		return new AiClientSurface_FakeAvailability( true );
	}

	public static function modelMetadataDirectory(): \WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface {
		return new AiClientSurface_CollisionModelMetadataDirectory(
			array( AiClientSurface::COLLISION_SHARED_MODEL, AiClientSurface::COLLISION_A_ONLY_MODEL )
		);
	}
}

final class AiClientSurface_CollisionProviderB implements \WordPress\AiClient\Providers\Contracts\ProviderInterface {
	public static function metadata(): \WordPress\AiClient\Providers\DTO\ProviderMetadata {
		return new \WordPress\AiClient\Providers\DTO\ProviderMetadata(
			AiClientSurface::COLLISION_PROVIDER_B_ID,
			'Component Fuzz Collision B',
			\WordPress\AiClient\Providers\Enums\ProviderTypeEnum::server()
		);
	}

	public static function model(
		string $modelId,
		?\WordPress\AiClient\Providers\Models\DTO\ModelConfig $modelConfig = null
	): \WordPress\AiClient\Providers\Models\Contracts\ModelInterface {
		if ( ! in_array( $modelId, array( AiClientSurface::COLLISION_SHARED_MODEL, AiClientSurface::COLLISION_B_ONLY_MODEL ), true ) ) {
			throw new \WordPress\AiClient\Common\Exception\InvalidArgumentException( 'Unknown collision model B: ' . $modelId );
		}

		return new AiClientSurface_CollisionTextModel(
			self::metadata(),
			$modelId,
			$modelConfig ?? new \WordPress\AiClient\Providers\Models\DTO\ModelConfig()
		);
	}

	public static function availability(): \WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface {
		return new AiClientSurface_FakeAvailability( true );
	}

	public static function modelMetadataDirectory(): \WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface {
		return new AiClientSurface_CollisionModelMetadataDirectory(
			array( AiClientSurface::COLLISION_SHARED_MODEL, AiClientSurface::COLLISION_B_ONLY_MODEL )
		);
	}
}

final class AiClientSurface_CollisionModelMetadataDirectory implements
	\WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface {

	/** @var list<string> */
	private array $model_ids;

	/**
	 * @param list<string> $model_ids Model identifiers exposed by the provider.
	 */
	public function __construct( array $model_ids ) {
		$this->model_ids = array_values( $model_ids );
	}

	public function listModelMetadata(): array {
		return array_map(
			static fn( string $model_id ): \WordPress\AiClient\Providers\Models\DTO\ModelMetadata =>
				AiClientSurface_CollisionTextModel::metadata_for( $model_id ),
			$this->model_ids
		);
	}

	public function hasModelMetadata( string $modelId ): bool {
		return in_array( $modelId, $this->model_ids, true );
	}

	public function getModelMetadata( string $modelId ): \WordPress\AiClient\Providers\Models\DTO\ModelMetadata {
		if ( ! $this->hasModelMetadata( $modelId ) ) {
			throw new \WordPress\AiClient\Common\Exception\InvalidArgumentException( 'Unknown collision model: ' . $modelId );
		}

		return AiClientSurface_CollisionTextModel::metadata_for( $modelId );
	}
}

final class AiClientSurface_CollisionTextModel implements
	\WordPress\AiClient\Providers\Models\Contracts\ModelInterface,
	\WordPress\AiClient\Providers\Models\TextGeneration\Contracts\TextGenerationModelInterface {

	private \WordPress\AiClient\Providers\DTO\ProviderMetadata $provider_metadata;

	private string $model_id;

	private \WordPress\AiClient\Providers\Models\DTO\ModelConfig $config;

	public function __construct(
		\WordPress\AiClient\Providers\DTO\ProviderMetadata $provider_metadata,
		string $model_id,
		\WordPress\AiClient\Providers\Models\DTO\ModelConfig $config
	) {
		$this->provider_metadata = $provider_metadata;
		$this->model_id          = $model_id;
		$this->config            = $config;
	}

	public static function metadata_for( string $model_id ): \WordPress\AiClient\Providers\Models\DTO\ModelMetadata {
		$options = array_map(
			static fn( \WordPress\AiClient\Providers\Models\Enums\OptionEnum $option ) =>
				new \WordPress\AiClient\Providers\Models\DTO\SupportedOption( $option ),
			\WordPress\AiClient\Providers\Models\Enums\OptionEnum::cases()
		);

		return new \WordPress\AiClient\Providers\Models\DTO\ModelMetadata(
			$model_id,
			'Component Fuzz ' . $model_id,
			array(
				\WordPress\AiClient\Providers\Models\Enums\CapabilityEnum::textGeneration(),
				\WordPress\AiClient\Providers\Models\Enums\CapabilityEnum::chatHistory(),
			),
			$options
		);
	}

	public function metadata(): \WordPress\AiClient\Providers\Models\DTO\ModelMetadata {
		return self::metadata_for( $this->model_id );
	}

	public function providerMetadata(): \WordPress\AiClient\Providers\DTO\ProviderMetadata {
		return $this->provider_metadata;
	}

	public function setConfig( \WordPress\AiClient\Providers\Models\DTO\ModelConfig $config ): void {
		$this->config = $config;
	}

	public function getConfig(): \WordPress\AiClient\Providers\Models\DTO\ModelConfig {
		return $this->config;
	}

	public function generateTextResult( array $prompt ): \WordPress\AiClient\Results\DTO\GenerativeAiResult {
		$last_text = '';
		foreach ( $prompt as $message ) {
			if ( ! $message instanceof \WordPress\AiClient\Messages\DTO\Message ) {
				continue;
			}
			foreach ( $message->getParts() as $part ) {
				if ( null !== $part->getText() ) {
					$last_text = $part->getText();
				}
			}
		}

		$text = implode(
			':',
			array(
				'component-fuzz-collision',
				$this->provider_metadata->getId(),
				$this->model_id,
				$last_text,
			)
		);

		return new \WordPress\AiClient\Results\DTO\GenerativeAiResult(
			'collision-result-' . substr( sha1( $text ), 0, 12 ),
			array(
				new \WordPress\AiClient\Results\DTO\Candidate(
					new \WordPress\AiClient\Messages\DTO\ModelMessage(
						array( new \WordPress\AiClient\Messages\DTO\MessagePart( $text ) )
					),
					\WordPress\AiClient\Results\Enums\FinishReasonEnum::stop()
				),
			),
			new \WordPress\AiClient\Results\DTO\TokenUsage( strlen( $last_text ), strlen( $text ), strlen( $last_text ) + strlen( $text ) ),
			$this->providerMetadata(),
			$this->metadata(),
			array(
				'providerId' => $this->provider_metadata->getId(),
				'modelId'    => $this->model_id,
				'promptCount' => count( $prompt ),
			)
		);
	}
}
