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

					fetch(
						`${window.location.protocol}//${window.location.hostname}/wp-json/newspack-extended-access/v1/google/register`,
						{
							cache: 'no-store',
							method: 'POST',
							headers: {
								'Content-type': 'text/plain',
								'X-WP-Nonce': authenticationSettings.nonce,
								'X-WP-Post-ID': authenticationSettings.postID
							},
							body: gaaUser.credential
						}
					)
						.then(response => response.json())
						.then(
							userState => {
								if (userState.grantReason === 'SUBSCRIBER') {
									if (window.localStorage) {
										if (localStorage.getItem('unlocked') && localStorage['unlocked'] === "true" && userState.granted === false) {
											localStorage.removeItem('unlocked');
										}
									}
								} else if (userState.grantReason === 'METERING') {
									if (window.localStorage) {
										if (userState.granted === true) {
											localStorage['unlocked'] = true;
											window.location.reload();	
										}
									}
								}
								resolve(userState);
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
			fetch(
				`${window.location.protocol}//${window.location.hostname}/wp-json/newspack-extended-access/v1/login/status`,
				{
					cache: 'no-store',
					method: 'GET',
					headers: {
						'Content-type': 'text/plain',
						'X-WP-Nonce': authenticationSettings.nonce,
						'X-WP-Post-ID': authenticationSettings.postID
					},
				}
			)
				.then(response => response.json())
				.then(
					userState => {
						resolve(userState);
					}
				);
		}
	);

	/**
	 * Fires when Extended Access grants permission.
	 */
	unlockArticle = () => {
		if (window.localStorage) {
			if (!localStorage.getItem('unlocked')) {
				localStorage['unlocked'] = true;
				window.location.reload();
			}
		}
	}

	/**
	 * Display custom paywall instead of Google Intervention Dialog.
	 */
	showPaywall = () => {
		fetch(
			`${window.location.protocol}//${window.location.hostname}/wp-json/newspack-extended-access/v1/unlock-article`,
			{
				cache: 'no-store',
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'X-WP-Post-ID': authenticationSettings.postID,
					'X-WP-Nonce': authenticationSettings.nonce
				}
			}
		)
			.then(response => response.json())
			.then(jsonData => {
				if (jsonData.status === 'UNLOCKED') {
					if (window.localStorage) {
						if (localStorage.getItem('unlocked') && localStorage['unlocked'] === "true") {
							localStorage.removeItem('unlocked');
						}
					}
					window.location.reload();
				}
			});
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
