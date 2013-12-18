<?php
abstract class Hooks {
	/**
	 * All hooks and their callbacks are stored here.
	 *
	 * @since 1.0.0
	 *
	 * @access protected
	 * @static
	 * @var array
	 */
	protected static $registry = array();
	
	/**
	 * An index of hooks that have been sorted.
	 *
	 * @since 1.0.0
	 *
	 * @access protected
	 * @static
	 * @var array
	 */
	protected static $sorted = array();
	
	/**
	 * An index of called hooks and their call count.
	 *
	 * @since 1.0.0
	 *
	 * @access protected
	 * @static
	 * @var array
	 */
	protected static $called = array();
	
	/**
	 * A backtrace of the hooks as they are started/ended.
	 *
	 * @since 1.0.0
	 *
	 * @access protected
	 * @static
	 * @var array
	 */
	protected static $current = array();
	
	/**
	 * Create a unique ID for the callback, based on it's
	 * nature, associated tag, and priority.
	 *
	 * To just test if a hook id is set
	 *
	 * @since 1.0.0
	 *
	 * @param callable $callback The callback in question.
	 * @param string   $hook     The tag the callback is being added to.
	 * @param bool|int $priority The priority of the callback.
	 *
	 * @return string The unique id for the callback.
	 */
	protected static function _unique_id( $callback, $hook, $priority ) {
		// Internal use, for enquring unique ids
		static $hook_count = 0;
		
		// If a function name, just use that
		if ( is_string( $callback ) ) {
			return $callback;
		}
		
		// Convert into array( object, method ) form
		if ( is_object( $callback ) ) {
			$callback = array( $callback, '' );
		} else {
			$callback = (array) $callback;
		}
		
		if ( is_object( $callback[0] ) ) {
			// Callback is an object, this can get complicated.
			if ( function_exists( 'spl_object_hash' ) ) {
				// Just use the object's hash and tack on the method name
				return spl_object_hash( $callback[0] ) . $callback[1];
			} else {
				// Create the id from the objects class and method
				$id = get_class( $callback[0] ) . $callback[1];
				
				// Check if this object already has a filter_id assigned
				if ( ! isset( $callback[0]->__hook_id ) ) {
					// Nope, we'll have to create it
					
					// Get the hook count for this tag, use $hook_count if not
					if ( isset( self::$registry[ $hook ][ $priority ] ) ) {
						$id .= count( (array) self::$registry[ $hook ][ $priority ] );
					} else {
						$id .= $hook_count;
					}
					
					// Attach the hook count to the object
					$callback[0]->__hook_id = $hook_count;
					
					// Increment hook count
					++$hook_count;
				} else {
					// Append the hook_id to the unique id
					$id .= $callback[0]->__hook_id;
				}
	
				return $id;
			}
		} elseif ( is_string( $callback[0] ) ) {
			// Callback is a static class, use Class::method notation
			return $callback[0] . '::' . $callback[1];
		} else {
			// No way is this a valid callback
			throw new Exception( 'Invalid callback, must be an object or string.' );
		}
	}
	
	/**
	 * Add a callback to the desired hook.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $hook          The name of the hook to add to.
	 * @param callable $callback      The callback to add.
	 * @param int      $priority      Optional The priority of the callback (default: 10)
	 * @param int      $accepted_args Optional The number of arguments the callback takes (default: 1)
	 *
	 * @return bool true
	 */
	public static function add( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		// Get the unique id for the callback
		$id = self::_unique_id( $callback, $hook, $priority );
		
		// Add the callback to the tag/priority/id address
		self::$registry[ $hook ][ $priority ][ $id ] = array(
			'callback' => $callback,
			'accepted_args' => $accepted_args
		);
		
		// Umark the tag as being sorted
		unset( self::$sorted[ $hook ] );
		
		return true;
	}
	
	/**
	 * Remove a callback from the desired hook.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $hook      The name of the hook to add to.
	 * @param callable $callback  The callback to add.
	 * @param int      $priority  Optional The priority of the callback (default: 10)
	 *
	 * @return bool Wether or not it existed in the first place.
	 */
	public static function remove( $hook, $callback, $priority = 10 ) {
		// Get the id of the callback, finish early if that doesn't check out
		if ( ! $id = self::_unique_id( $callback, $hook, $priority ) ) {
			return false;
		}
		
		// Check if the callback exists at that tag/priority/id address
		$exists = isset( self::$registry[ $hook ][ $priority ][ $id ] );
		
		if ( $exists ) {
			// Remove it
			unset( self::$registry[ $hook ][ $priority ][ $id ] );
			
			// Also remove the entire priority block if empty
			if ( empty( self::$registry[ $hook ][ $priority ] ) ) {
				unset( self::$registry[ $hook ][ $priority ] );
			}
			
			// Also unmark the tag as sorted
			unset( self::$sorted[ $hook ] );
		}
		
		// Return the result of the exists test
		return $exists;
	}
	
	/**
	 * Remove any instance of a callback from the desired hook.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $hook     The name of the hook to add to.
	 * @param callable $callback The callback to add.
	 *
	 * @return bool Wether or not it existed in the first place.
	 */
	public static function removeAny( $hook, $callback ) {
		$exists = self::exists( $hook, $callback );
	
		while ( $priority = self::exists( $hook, $callback ) ) {
			self::remove( $hook, $callback, $priority );
		}
		
		return $exists;
	}
	
	/**
	 * Remove all callbacks for a desired tag (and priority)
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook     The name of the hook to add to.
	 * @param int    $priority Optional The priority block to remove.
	 *
	 * @return bool Wether or not it existed in the first place.
	 */
	public static function removeAll( $hook, $priority = false ) {
		// Check if the tag exists
		if ( isset( self::$registry[ $hook ] ) ) {
			if ( false == $priority ) {
				// Unset the entire tag entry
				unset( self::$registry[ $hook ] );
			} elseif ( isset( self::$registry[ $hook ][ $priority ] ) ) {
				// Unset just the priority block (if present)
				unset( self::$registry[ $hook ][ $priority ] );
			} else {
				// Desired priority block doesn't even exist
				return false;
			}
		} else {
			// Desired tag doesn't even exist
			return false;
		}
	
		// Unmark the tag as sorted (if marked)
		if ( isset( self::$sorted[ $hook ] ) ) {
			unset( self::$sorted[ $hook ] );
		}
	
		return true;
	}
	
	/**
	 * Internal use; call the all hook, mark this hook
	 * as the current on, and increment its called count.
	 *
	 * Will return true or false if any callbacks exist and the
	 * hook should proceed.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook The name of the hook to log.
	 * @param array  $args The arguments from the calling method.
	 *
	 * @return bool Wether or not to proceed with calling.
	 */
	protected static function _log( $hook, $args ) {
		// Append as the current hook
		self::$current[] = $hook;
		
		// Call the 'all' hook if present
		if ( isset( self::$registry['all'] ) ) {
			self::_call( 'all', $args );
		}
		
		// Check that the hook is registered
		if ( ! isset( self::$registry[ $hook ] ) ) {
			// If not, remove it as the current hook
			array_pop( self::$current );
			return false;
		}
	
		// Set/increment the call count for this hook
		if ( ! isset( self::$called[ $hook ] ) ) {
			self::$called[ $hook ] = 1;
		} else {
			++self::$called[ $hook ];
		}
		
		return true;
	}
	
	/**
	 * Internal use: key-sort the hook's registry
	 * if not yet sorted, and mark it as so.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook The name of the hook block to sort.
	 */
	protected static function _sort( $hook ) {
		if ( ! isset( self::$sorted[ $hook ] ) ) {
			ksort( self::$registry[ $hook ] );
			self::$sorted[ $hook ] = true;
		}
		reset( self::$registry[ $hook ] );
	}
	
	/**
	 * Actually call the individual callbacks for the desired hook.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook The name of the hook block to go through.
	 * @param array  $args The arguments from the calling function.
	 */
	protected static function _call( $hook, $args ) {
		// Make sure the hook block is at the beginning
		reset( self::$registry[ $hook ] );
	
		do {
			// Loop through each callback...
			foreach( (array) current( self::$registry[ $hook ] ) as $hook ) {
				if ( ! is_null( $hook['callback'] ) ) {
					// Call the callback, passing the appropriate number of arguments
					call_user_func_array( $hook['callback'], array_slice( $args, 0, (int) $hook['accepted_args'] ) );
				}
			}
		} while ( next( self::$registry[ $hook ] ) !== false );
	
		// Unmark as the current hook
		array_pop( self::$current );
	}
	
	/**
	 * Call all callbacks for the desired hook.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook    The name of the hook block to go through.
	 * @param mixed  $args... Optional Other arguments to pass to the callbacks.
	 */
	public static function call( $hook ) {
		// Get the arguments
		$args = func_get_args();
		
		// Log and check if we can proceed
		if ( ! self::_log( $hook, $args ) ) {
			return;
		}
		
		// Make sure the hook block is sorted
		self::_sort( $hook );
		
		// Get rid of the first argument ($hook)
		array_shift( $args );
		
		// Call the hook with the passed arguments
		self::_call( $hook, $args );
	}
	
	/**
	 * Same as Hooks::call(), but all arguments are
	 * passed in a single array.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook The name of the hook block to go through.
	 * @param mixed  $args The arguments to pass to the callbacks.
	 */
	public static function callArray( $hook, $args ) {
		// Prepend $args with $hook
		array_unshift( $args, $hook );
		
		// Call Hooks::call() with the $args array as individual arguments
		call_user_func_array( array( self, 'call' ), $args );
	}
	
	/**
	 * Run a value through the desired hook.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook    The name of the hook block to go through.
	 * @param mixed  $value   The value to run through the hooks.
	 * @param mixed  $args... Optional Other arguments to pass to the callbacks.
	 *
	 * @return mixed The processed $value.
	 */
	public static function apply( $hook, $value ) {
		// Get the arguments
		$args = func_get_args();
		
		// Log and check if we can proceed
		if ( ! self::_log( $hook, $args ) ) {
			// Return the original value if not
			return $value;
		}
		
		// Make sure the hook block is sorted
		self::_sort( $hook );
		
		// Get rid of the first argument ($hook)
		array_shift( $args );
	
		// Run through the callbacks
		do {
			// Loop through each callback...
			foreach( (array) current( self::$registry[ $hook ] ) as $hook ) {
				if ( ! is_null( $hook['callback'] ) ) {
					// Apply the callback, passing the appropriate number of arguments
					$value = call_user_func_array( $hook['callback'], array_slice( $args, 0, (int) $hook['accepted_args'] ) );
				}
			}
		} while ( next( self::$registry[ $hook ] ) !== false );
	
		// Unmark as the current hook
		array_pop( self::$current );
	
		// Return the processed value
		return $value;
	}
	
	/**
	 * Same as Hooks::call(), but all arguments are
	 * passed in a single array.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook The name of the hook block to go through.
	 * @param mixed  $args The arguments to pass to the callbacks.
	 *
	 * @return mixed The processed $value.
	 */
	public static function applyArray( $hook, $value, $args ) {
		// Prepend $args with $hook and $value
		array_unshift( $args, $hook );
		array_unshift( $args, $value );
		
		// Call Hooks::apply() with the $args array as individual arguments
		return call_user_func_array( array( self, 'apply' ), $args );
	}
	
	/**
	 * Get the current hook being run.
	 *
	 * @since 1.0.0
	 *
	 * @return string The name of the current hook.
	 */
	public static function current(){
		return end( self::$current );
	}
	
	/**
	 * Get the number of times a hook's been called.
	 *
	 * Will return 0 if not at all (rather than false).
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook The name of the hook to check.
	 *
	 * @return int The number of times the hook's been called.
	 */
	public static function did( $hook ){
		if ( ! isset( self::$called[ $hook ] ) ) {
			return 0;
		}
	
		return self::$called[ $hook ];
	}
	
	/**
	 * Check if a hook (and callback) exists.
	 *
	 * Will return the priority of the callback if found,
	 * or TRUE/FALSE for the hook itself. This means it may
	 * return 0, so use === to compare when checking for a
	 * specific callback.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $hook     The name of the hook to check.
	 * @param callable $callback Optional The callback to check for too.
	 *
	 * @return bool|int Wether it exists or not (or the priority of the callback)
	 */
	public static function exists( $hook, $callback = false ){
		// Test if the hook exists in the registry and isn't empty.
		$has = isset( self::$registry[ $hook ] ) && ! empty( self::$registry[ $hook ] );
		
		// Return $has if it's false or no callback was specified
		if ( false === $callback || false == $has ) {
			return $has;
		}
		
		// Get the id of the callback, finish early if that doesn't check out
		if ( ! $id = self::_unique_id( $callback, $hook, $priority ) ) {
			return false;
		}
	
		// Loop through each priority block
		foreach ( (array) array_keys( self::$registry[ $hook ] ) as $priority => $callbacks ) {
			// Loop through each callback
			if ( isset( $callbacks[ $id ] ) ) {
				// Found! Return the priority
				return $priority;
			}
		}
	
		return false;
	}
	
	/**
	 * Get the entire hook registry. (READ ONLY)
	 * 
	 * Optionally, just get the block for a specific hook.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook Optional The name of the specific hook block to get.
	 *
	 * @return array|bool The desired array block, FALSE if it doesn't exist.
	 */
	public static function registered( $hook = null ) {
		if ( null == $hook ) {
			return self::$registry;
		}
		
		if ( isset( self::$registry[ $hook ] ) ) {
			return self::$registry[ $hook ];
		}
		
		return array();
	}
}
