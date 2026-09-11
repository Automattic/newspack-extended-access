/**
 * Newspack SwG Library.
 *
 * Initializes GAA and defines required callbacks to
 * register / login to site via SwG, check post status
 * and unlock article.
 *
 * @link   https://www.newspack.com
 * @file   This files defines SwG required methods and callback for Newspack specific functionality.
 * @author Newspack
 * @since  1.0
 * /

/**
 * Parses JWT token and converts into equivalent JSON object.
 *
 * @param {string} token JWT Token to be parse.
 * @returns {Object} Parsed JWT as JSON Object.
 */
function parseJwt(token) {
	var base64Url = token.split('.')[1];
	var base64 = base64Url.replace(/-/g, '+').replace(/_/g, '/');
	var jsonPayload = decodeURIComponent(
		window.atob(base64).split('').map(
			function (c) {
				return '%' + ('00' + c.charCodeAt(0).toString(16)).slice(-2);
			}
		).join('')
	);

	return JSON.parse(jsonPayload);
}

/**
 * Initializes GaaMetering for SwG.
 */
function initGaaMetering() {

	/**
	 * Referrers to be allowed.
	 */
	const allowedReferrers = authenticationSettings.allowedReferrers;

	/**
	 * Base URL for this plugin's REST namespace, localized from `rest_url()` so
	 * it carries the site's port, subdirectory and permalink structure. Always
	 * ends with a slash.
	 */
	const restURL = authenticationSettings.restURL;

	/**
	 * Calls one of this plugin's REST endpoints and returns its parsed body.
	 *
	 * Rejects on a non-2xx status so callers cannot mistake an error body for a
	 * userState, and adopts the nonce each response carries. Both userState
	 * endpoints re-issue the reader's session, which invalidates the nonce the
	 * page was rendered with — without adopting the new one, every later call
	 * fails its nonce check.
	 *
	 * @param {string} endpoint Endpoint path, relative to the plugin's REST namespace.
	 * @param {Object} options Additional fetch options.
	 * @returns {Promise<Object>} Parsed JSON body.
	 */
	function fetchFromRestAPI(endpoint, options = {}) {
		return fetch(
			`${restURL}${endpoint}`,
			Object.assign(
				{ cache: 'no-store', credentials: 'same-origin' },
				options,
				{
					headers: Object.assign(
						{
							'X-WP-Nonce': authenticationSettings.nonce,
							'X-WP-Post-ID': authenticationSettings.postID
						},
						options.headers || {}
					)
				}
			)
		).then(
			response => {
				const refreshedNonce = response.headers.get('X-WP-Nonce');
				if (refreshedNonce) {
					authenticationSettings.nonce = refreshedNonce;
				}
				if (!response.ok) {
					throw new Error(`Extended Access request to ${endpoint} failed with status ${response.status}.`);
				}
				return response.json();
			}
		);
	}

	/**
	 * Login Existing User Promise callback handler.
	 */
	handleLoginPromise = new Promise(
		() => {
			GaaMetering.getLoginPromise().then(
				() => {
					// Capture the full URL, including query parameters, to return the reader to
					// after login. The Extended Access parameters live there and not in the
					// permalink, so dropping them leaves the flow unable to resume.
					const loginUrl = new URL(authenticationSettings.myAccountURL, window.location.origin);
					// 'redirect' param is used by newspack plugin's reader-activation to prepare auth callback URL.
					loginUrl.searchParams.set('redirect', window.location.href);
					// 'redirect_to' is what wp-login.php honours, on sites with no My Account page.
					loginUrl.searchParams.set('redirect_to', window.location.href);
					// Redirect to a login page for existing users to login.
					window.location = loginUrl.toString();
				}
			);
		}
	);

	/**
	 * Register New User Promise callback handler.
	 */
	registerUserPromise = new Promise(
		(resolve) => {
			// Get the information for the user who has just registered.
			GaaMetering.getGaaUserPromise().then(
				(gaaUser) => {
					// Send that information to your Registration endpoint to register the user and
					// return the userState for the newly registered user.

					fetchFromRestAPI(
						'google/register',
						{
							method: 'POST',
							headers: { 'Content-type': 'text/plain' },
							body: gaaUser.credential
						}
					)
						.then(
							userState => {
								// A metered grant is recorded as a server-side cookie, so the
								// article only becomes readable on the next render.
								if (userState.grantReason === 'METERING' && userState.granted === true) {
									window.location.reload();
								}
								resolve(userState);
							}
						)
						.catch(
							error => {
								console.error(error);
								// Settle rather than hang: an unresolved promise leaves
								// GaaMetering waiting forever with the regwall on screen.
								resolve({ granted: false });
							}
						);
				}
			);
		}
	);

	/**
	 * Check whether publisher has provided access to the User or not.
	 */
	publisherEntitlementPromise = new Promise(
		(resolve) => {
			resolve({ granted: false });
		}
	);

	/**
	 * Check whether publisher has provided access to the User or not.
	 */
	getUserState = new Promise(
		(resolve) => {
			fetchFromRestAPI('login/status', { method: 'GET' })
				.then(
					userState => {
						resolve(userState);
					}
				)
				.catch(
					error => {
						console.error(error);
						// Treat an unreachable status endpoint as "no grant": the
						// paywall stays as the server rendered it.
						resolve({ granted: false });
					}
				);
		}
	);

	/**
	 * Fires when Google grants Extended Access — i.e. when the reader dismisses
	 * the Extended Access CTA. The grant is recorded server-side by hitting
	 * /unlock-article, which sets a per-(user, post) cookie that
	 * SinglePost_Subscription reads to lift the content gate.
	 */
	unlockArticle = () => {
		fetchFromRestAPI('unlock-article', { method: 'POST' })
			.then(jsonData => {
				// Reload only on a first-time grant, which is the one case where
				// the rendered page is now out of date. ALREADY_UNLOCKED and
				// SUBSCRIBER describe a page that is already showing the article,
				// so reloading on those would spin.
				if (jsonData.status === 'UNLOCKED') {
					window.location.reload();
				}
			})
			.catch(error => console.error(error));
	}

	/**
	 * Fires when Google declines Extended Access, or when the reader clicks
	 * Subscribe on the EA CTA. In either case the WC Memberships paywall is
	 * already rendered on the page (server-side) and exposes the publisher's
	 * subscribe button, so this callback is intentionally a no-op — we do not
	 * want to unlock the article here.
	 */
	showPaywall = () => {
		// Intentionally empty. See JSDoc.
	}

	/**
	 * Initialize GAA for Extended Access.
	 */
	GaaMetering.init(
		{
			googleApiClientId: authenticationSettings.googleClientApiID,
			userState: getUserState,
			allowedReferrers: allowedReferrers,
			handleLoginPromise: handleLoginPromise,
			registerUserPromise: registerUserPromise,
			publisherEntitlementPromise: getUserState,
			unlockArticle: unlockArticle,
			showPaywall: showPaywall,
		}
	);
}
