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
 * Holds logged-in user email for few REST Endpoints.
 */
let loggedInUserEmail = "";

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
 * Creates a cookie.
 *
 * @param {string} name Name of the cookie.
 * @param {string} value Value to be stored.
 * @param {number} days Expire cookie after specified days.
 */
function setCookie(name, value, days) {
	var expires = "";
	if (days) {
		var date = new Date();
		date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
		expires = "; expires=" + date.toUTCString();
	}
	document.cookie = name + "=" + (value || "") + expires + "; path=/";
}

/**
 * Retrieves cookie value.
 *
 * @param {string} name Name of the cookie.
 * @returns {string|null} Returns string value if valid cookie is present else null.
 */
function getCookie(name) {
	var nameEQ = name + "=";
	var ca = document.cookie.split(';');
	for (var i = 0; i < ca.length; i++) {
		var c = ca[i];
		while (c.charAt(0) == ' ') c = c.substring(1, c.length);
		if (c.indexOf(nameEQ) == 0) return c.substring(nameEQ.length, c.length);
	}
	return null;
}

/**
 * Deletes cookie.
 *
 * @param {string} name Name of the cookie.
 */
function eraseCookie(name) {
	document.cookie = name + '=; Path=/; Expires=Thu, 01 Jan 1970 00:00:01 GMT;';
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
					// Capture full URL, including URL parameters, to redirect the user to after login
					const redirectUri = encodeURIComponent(window.location.href);
					// Redirect to a login page for existing users to login.
					window.location = `${window.location.protocol}//${window.location.hostname}/my-account?redirect_to=${redirectUri}`;
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

					const gaaUserDecoded = parseJwt(gaaUser.credential);
					loggedInUserEmail = gaaUserDecoded.email;
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
								// Refresh page only when it is not already unlocked
								if (window.localStorage) {
									if (localStorage.getItem('unlocked') && localStorage['unlocked'] === "true" && userState.granted === false) {
										localStorage.removeItem('unlocked');
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
						// Refresh page only when it is not already unlocked
						if (window.localStorage) {
							if (localStorage.getItem('unlocked') && localStorage['unlocked'] === "true" && userState.granted === false) {
								localStorage.removeItem('unlocked');
							}
						}
						loggedInUserEmail = userState.email;
						resolve(userState);
					}
				);
		}
	);

	/**
	 * Fires when Extended Access grants permission.
	 */
	unlockArticle = () => {
		fetch(
			`${window.location.protocol}//${window.location.hostname}/wp-json/newspack-extended-access/v1/unlock-article`,
			{
				cache: 'no-store',
				method: 'GET',
				headers: {
					'X-WP-Post-ID': authenticationSettings.postID,
					'X-WP-User-Email': loggedInUserEmail
				}
			}
		)
			.then(response => response.json())
			.then(jsonData => {
				if (jsonData.status === 'UNLOCKED') {
					if (getCookie(jsonData.c) === null) {
						setCookie(jsonData.c, 'true', 365);
						window.location.reload();
					}
				}
			});
	}

	/**
	 * Display custom paywall instead of Google Intervention Dialog.
	 */
	showPaywall = () => {
		// Redirect to a subscription page.
		window.location = `${window.location.protocol}//${window.location.hostname}/subscribe`;
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
