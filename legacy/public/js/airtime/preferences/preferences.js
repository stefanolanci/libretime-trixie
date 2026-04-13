function showErrorSections() {
  var selector = $("[id$=-settings]");
  selector.each(function (i) {
    var el = $(this);
    var errors = el.find(".errors");
    if (errors.length > 0) {
      el.show();
      $(window).scrollTop(errors.position().top);
    }
  });
}

function setConfigureMailServerListener() {
  var configMailServer = $("#configureMailServer");
  configMailServer.click(function (event) {
    setMailServerInputReadonly();
  });

  var msRequiresAuth = $("#msRequiresAuth");
  msRequiresAuth.click(function (event) {
    setMsAuthenticationFieldsReadonly($(this));
  });
}

function setEnableSystemEmailsListener() {
  var enableSystemEmails = $("#enableSystemEmail");
  enableSystemEmails.click(function (event) {
    setSystemFromEmailReadonly();
  });
}

function setPodcastAutoSmartblockReadonly() {
  var disablePodcastAutomSmartblock = $("#preferences_general-podcastAutoSmartblock-0");
  var enablePodcastAutomSmartblock = $("#preferences_general-podcastAutoSmartblock-1");
  var podcastOverride = $("#preferences_general-podcastAlbumOverride-1");
  if ($(podcastOverride).is(":checked")) {
    enablePodcastAutomSmartblock.removeAttr("readonly");
  } else {
    disablePodcastAutomSmartblock.prop("checked", true);
    disablePodcastAutomSmartblock.attr("readonly", "readonly");
    enablePodcastAutomSmartblock.attr("readonly", "readonly");
  }
}

function setSystemFromEmailReadonly() {
  var enableSystemEmails = $("#enableSystemEmail");
  var systemFromEmail = $("#systemEmail");
  if ($(enableSystemEmails).is(":checked")) {
    systemFromEmail.removeAttr("readonly");
  } else {
    systemFromEmail.attr("readonly", "readonly");
  }
}

function setMailServerInputReadonly() {
  var configMailServer = $("#configureMailServer");
  var mailServer = $("#mailServer");
  var port = $("#port");
  var requiresAuthCB = $("#msRequiresAuth");

  if (configMailServer.is(":checked")) {
    mailServer.removeAttr("readonly");
    port.removeAttr("readonly");
    requiresAuthCB.parent().show();
  } else {
    mailServer.attr("readonly", "readonly");
    port.attr("readonly", "readonly");
    requiresAuthCB.parent().hide();
  }

  setMsAuthenticationFieldsReadonly(requiresAuthCB);
}

function setTuneInSettingsListener() {
  var enableTunein = $("#preferences_tunein-enable_tunein");
  enableTunein.click(function (event) {
    setTuneInSettingsReadonly();
  });
}

function setTuneInSettingsReadonly() {
  var enableTunein = $("#preferences_tunein-enable_tunein");
  var stationId = $("#preferences_tunein-tunein_station_id");
  var partnerKey = $("#preferences_tunein-tunein_partner_key");
  var partnerId = $("#preferences_tunein-tunein_partner_id");

  if (enableTunein.is(":checked")) {
    stationId.removeAttr("readonly");
    partnerKey.removeAttr("readonly");
    partnerId.removeAttr("readonly");
  } else {
    stationId.attr("readonly", "readonly");
    partnerKey.attr("readonly", "readonly");
    partnerId.attr("readonly", "readonly");
  }
}

/*
 * Enable/disable mail server authentication fields
 */
function setMsAuthenticationFieldsReadonly(ele) {
  var email = $("#email");
  var password = $("#ms_password");
  var configureMailServer = $("#configureMailServer");

  if (ele.is(":checked") && configureMailServer.is(":checked")) {
    email.removeAttr("readonly");
    password.removeAttr("readonly");
  } else if (ele.not(":checked") || configureMailServer.not(":checked")) {
    email.attr("readonly", "readonly");
    password.attr("readonly", "readonly");
  }
}

function removeLogo() {
  $.post(
    baseUrl + "preference/remove-logo",
    { csrf_token: $("#csrf").val() },
    function (json) {
      // Reload without resubmitting the form
      location.href = location.href.replace(location.hash, "");
    },
  );
}

function deleteAllFiles() {
  var resp = confirm(
    $.i18n._("Are you sure you want to delete all the tracks in your library?"),
  );
  if (resp) {
    $.post(
      baseUrl + "preference/delete-all-files",
      { csrf_token: $("#csrf").val() },
      function (json) {
        location.reload();
      },
    );
  }
}

$(document).ready(function () {
  $(document).on("click", ".collapsible-header", function () {
    $(this).next().toggle("fast");
    $(this).toggleClass("closed");
    return false;
  });

  if ($("#tunein-settings").find(".errors").length > 0) {
    $(".collapsible-content#tunein-settings").show();
  }

  /* No longer using AJAX for this form. Zend + our code makes it needlessly hard to deal with. -- Albert
    $('#pref_save').live('click', function() {
        var data = $('#pref_form').serialize();
        var url = baseUrl+'Preference/index';

        $.post(url, {format: "json", data: data}, function(json){
            $('#content').empty().append(json.html);
            setTimeout(removeSuccessMsg, 5000);
            showErrorSections();
            setMailServerInputReadonly();
            setConfigureMailServerListener();
            setEnableSystemEmailsListener();
        });
    });*/

  // Delegated: preferences panel is often injected into #content after initial document.ready.
  // Bind by form + file + name: Zend id can vary; name is preferences_general[stationLogo].
  $(document).on(
    "change",
    "#pref_form input[type=file][name*='stationLogo']",
    function () {
      var preview = $("#station-logo-preview");
      var $input = $(this);
      if (this.files && this.files[0]) {
        preview.show().css({ visibility: "visible", opacity: "" });
        var reader = new FileReader();
        reader.onload = function (ev) {
          preview
            .attr("src", ev.target.result)
            .css({ visibility: "visible", display: "block" });
        };

        if (validateImage(this.files[0], $input)) {
          reader.readAsDataURL(this.files[0]);
          $("#logo-remove-btn").show();
        } else {
          $input.val("").replaceWith($input.clone(true));
          preview.hide();
        }
      } else {
        preview.hide();
      }
    },
  );

  var preview = $("#station-logo-preview");
  var previewSrc = preview.length ? preview.attr("src") : "";
  if (previewSrc && previewSrc.indexOf("images/") > -1) {
    $("#logo-remove-btn").hide();
  }

  showErrorSections();

  setMailServerInputReadonly();
  setPodcastAutoSmartblockReadonly();
  setSystemFromEmailReadonly();
  setConfigureMailServerListener();
  setEnableSystemEmailsListener();
  setTuneInSettingsReadonly();
  setTuneInSettingsListener();
});
