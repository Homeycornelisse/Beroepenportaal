(function($){
  var repeaterConfig = {
    experience: { fields: ["role", "company", "period", "description"], labels: ["Functie", "Organisatie", "Periode", "Beschrijving"] },
    education: { fields: ["title", "school", "period", "description"], labels: ["Opleiding", "School", "Periode", "Beschrijving"] },
    skills: { fields: ["name", "level"], labels: ["Skill", "Niveau (1-5)"] },
    certificates: { fields: ["name", "issuer", "year"], labels: ["Certificaat", "Uitgever", "Jaar"] }
  };
  var templateStyles = {
    modern: { main: "#15776a", side: "#5caea3", text: "#111827" },
    classic: { main: "#003082", side: "#1f2937", text: "#111827" },
    compact: { main: "#b45309", side: "#7c2d12", text: "#111827" }
  };

  function parseDefaults($scope){
    var raw = $scope.attr("data-bp-cv-defaults") || "{}";
    try { return JSON.parse(raw); } catch(e) { return {}; }
  }

  function esc(value){
    return String(value || "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  function bindSimpleFields($scope){
    function update(key){
      var value = $scope.find('[data-bp-cv-bind="' + key + '"]').first().val() || "";
      if(key === "first_name" || key === "last_name"){
        var first = $scope.find('[data-bp-cv-bind="first_name"]').first().val() || "";
        var last = $scope.find('[data-bp-cv-bind="last_name"]').first().val() || "";
        value = (first + " " + last).trim();
        key = "full_name";
      }
      $scope.find('[data-bp-cv-target="' + key + '"]').html(esc(value || "-"));
    }

    $scope.find("[data-bp-cv-bind]").each(function(){
      update($(this).attr("data-bp-cv-bind"));
    });

    $scope.on("input change", "[data-bp-cv-bind]", function(){
      update($(this).attr("data-bp-cv-bind"));
    });

    $scope.on("click", "[data-bp-cv-toggle-extra]", function(){
      var $extra = $scope.find(".bp-cv-extra").first();
      var open = !$extra.prop("hidden");
      $extra.prop("hidden", open);
      $(this).find("span").text(open ? "v" : "^");
    });
  }

  function photoPreview($scope){
    $scope.on("change", "[data-bp-cv-photo]", function(){
      var file = this.files && this.files[0];
      if(!file) return;
      $scope.find("[data-bp-cv-photo-label]").text(file.name);
      var url = URL.createObjectURL(file);
      $scope.find("[data-bp-cv-avatar]").each(function(){
        $(this).html('<img src="' + esc(url) + '" alt="Profiel foto" />');
      });
    });
  }

  function autoUpload($scope){
    function setDropState($zone, isActive){
      $zone.toggleClass("bp-dropzone--drag", !!isActive);
    }

    $scope.on("dragenter dragover", ".bp-cv-upload-zone", function(e){
      e.preventDefault();
      e.stopPropagation();
      setDropState($(this), true);
    });

    $scope.on("dragleave dragend drop", ".bp-cv-upload-zone", function(e){
      e.preventDefault();
      e.stopPropagation();
      setDropState($(this), false);
    });

    $scope.on("drop", ".bp-cv-upload-zone", function(e){
      var dt = e.originalEvent && e.originalEvent.dataTransfer;
      if(!dt || !dt.files || !dt.files.length) return;
      var $input = $(this).find('input[type=file][name="bp_cv_file"]').first();
      if(!$input.length) return;
      try {
        $input[0].files = dt.files;
      } catch(err){
        $input.trigger("click");
        return;
      }
      $input.trigger("change");
    });

    $scope.on("change", 'input[type=file][name="bp_cv_file"]', function(){
      var $input = $(this);
      var file = this.files && this.files[0];
      if(file){
        $input.closest(".bp-cv-upload-zone").find(".bp-drop-title").text(file.name);
      }
      var $form = $input.closest("form");
      if($form.length && $form[0]) $form[0].submit();
    });

    $scope.on("click", '[data-bp-cv-replace="1"]', function(e){
      e.preventDefault();
      var $input = $scope.find('input[type=file][name="bp_cv_file"]').first();
      if($input.length) $input.trigger("click");
    });
  }

  function renderRepeaterItem(type, item){
    var cfg = repeaterConfig[type];
    if(!cfg) return "";
    var html = '<div class="bp-cv-repeat-item">';
    if(type === "skills"){
      html += '<div class="bp-cv-row">';
      html += '<label class="bp-cv-field"><span>' + cfg.labels[0] + '</span><input type="text" data-bp-item-field="name" value="' + esc(item.name || "") + '" /></label>';
      html += '<label class="bp-cv-field"><span>' + cfg.labels[1] + '</span><input type="number" min="1" max="5" data-bp-item-field="level" value="' + esc(item.level || 4) + '" /></label>';
      html += "</div>";
    } else if(type === "certificates"){
      html += '<div class="bp-cv-row">';
      html += '<label class="bp-cv-field"><span>Certificaat</span><input type="text" data-bp-item-field="name" value="' + esc(item.name || "") + '" /></label>';
      html += '<label class="bp-cv-field"><span>Uitgever</span><input type="text" data-bp-item-field="issuer" value="' + esc(item.issuer || "") + '" /></label>';
      html += "</div>";
      html += '<label class="bp-cv-field"><span>Jaar</span><input type="text" data-bp-item-field="year" value="' + esc(item.year || "") + '" /></label>';
    } else if(type === "education"){
      html += '<div class="bp-cv-row">';
      html += '<label class="bp-cv-field"><span>Opleiding</span><input type="text" data-bp-item-field="title" value="' + esc(item.title || "") + '" /></label>';
      html += '<label class="bp-cv-field"><span>School</span><input type="text" data-bp-item-field="school" value="' + esc(item.school || "") + '" /></label>';
      html += "</div>";
      html += '<label class="bp-cv-field"><span>Periode</span><input type="text" data-bp-item-field="period" value="' + esc(item.period || "") + '" /></label>';
      html += '<label class="bp-cv-field"><span>Beschrijving</span><textarea rows="3" data-bp-item-field="description">' + esc(item.description || "") + "</textarea></label>";
    } else {
      html += '<div class="bp-cv-row">';
      html += '<label class="bp-cv-field"><span>Functie</span><input type="text" data-bp-item-field="role" value="' + esc(item.role || "") + '" /></label>';
      html += '<label class="bp-cv-field"><span>Organisatie</span><input type="text" data-bp-item-field="company" value="' + esc(item.company || "") + '" /></label>';
      html += "</div>";
      html += '<label class="bp-cv-field"><span>Periode</span><input type="text" data-bp-item-field="period" value="' + esc(item.period || "") + '" /></label>';
      html += '<label class="bp-cv-field"><span>Beschrijving</span><textarea rows="3" data-bp-item-field="description">' + esc(item.description || "") + "</textarea></label>";
    }
    html += '<button type="button" class="bp-btn bp-btn-danger" data-bp-item-remove>Verwijderen</button>';
    html += "</div>";
    return html;
  }

  function collectRepeater($section){
    var items = [];
    $section.find(".bp-cv-repeat-item").each(function(){
      var data = {};
      $(this).find("[data-bp-item-field]").each(function(){
        data[$(this).attr("data-bp-item-field")] = $(this).val();
      });
      items.push(data);
    });
    return items;
  }

  function renderPreviewList($scope, type, items){
    var $targets = $scope.find('[data-bp-cv-list="' + type + '"]');
    if(!$targets.length) return;
    var html = "";
    if(type === "skills"){
      items.forEach(function(it){
        var lvl = Math.max(1, Math.min(5, parseInt(it.level || 4, 10)));
        html += '<div class="bp-cv-skill-row"><span>' + esc(it.name || "-") + "</span><span class=\"bp-cv-skill-dots\">" + "*".repeat(lvl) + ".".repeat(5 - lvl) + "</span></div>";
      });
    } else if(type === "certificates"){
      items.forEach(function(it){
        html += '<div class="bp-cv-prev-block"><div class="bp-cv-prev-title">' + esc(it.name || "-") + '</div><div class="bp-cv-prev-sub">' + esc((it.issuer || "") + " " + (it.year || "")) + "</div></div>";
      });
    } else if(type === "education"){
      items.forEach(function(it){
        html += '<div class="bp-cv-prev-block"><div class="bp-cv-prev-title">' + esc(it.title || "-") + '</div><div class="bp-cv-prev-sub">' + esc((it.school || "") + " | " + (it.period || "")) + '</div><div class="bp-cv-prev-desc">' + esc(it.description || "") + "</div></div>";
      });
    } else {
      items.forEach(function(it){
        html += '<div class="bp-cv-prev-block"><div class="bp-cv-prev-title">' + esc(it.role || "-") + '</div><div class="bp-cv-prev-sub">' + esc((it.company || "") + " | " + (it.period || "")) + '</div><div class="bp-cv-prev-desc">' + esc(it.description || "") + "</div></div>";
      });
    }
    $targets.each(function(){
      $(this).html(html || '<div class="bp-cv-prev-sub">Nog geen gegevens</div>');
    });
  }

  function initRepeaters($scope){
    var defaults = parseDefaults($scope);

    $scope.find("[data-bp-repeater]").each(function(){
      var $section = $(this);
      var type = $section.attr("data-bp-repeater");
      var items = Array.isArray(defaults[type]) ? defaults[type] : [];
      var $list = $section.find(".bp-cv-repeat-list");
      items.forEach(function(item){ $list.append(renderRepeaterItem(type, item)); });
      renderPreviewList($scope, type, collectRepeater($section));
    });

    $scope.on("click", "[data-bp-repeater-add]", function(){
      var $section = $(this).closest("[data-bp-repeater]");
      var type = $section.attr("data-bp-repeater");
      $section.find(".bp-cv-repeat-list").append(renderRepeaterItem(type, {}));
      renderPreviewList($scope, type, collectRepeater($section));
    });

    $scope.on("click", "[data-bp-item-remove]", function(){
      var $section = $(this).closest("[data-bp-repeater]");
      var type = $section.attr("data-bp-repeater");
      $(this).closest(".bp-cv-repeat-item").remove();
      renderPreviewList($scope, type, collectRepeater($section));
    });

    $scope.on("input change", "[data-bp-item-field]", function(){
      var $section = $(this).closest("[data-bp-repeater]");
      var type = $section.attr("data-bp-repeater");
      renderPreviewList($scope, type, collectRepeater($section));
    });
  }

  function initTemplates($scope){
    function applyTemplate(name){
      $scope.find("[data-bp-template-grid] [data-bp-template]").removeClass("is-active");
      $scope.find('[data-bp-template-grid] [data-bp-template="' + name + '"]').addClass("is-active");
      var style = templateStyles[name] || templateStyles.modern;
      $scope.find(".bp-cv-paper")
        .attr("data-bp-template", name)
        .css("--cv-primary", style.main)
        .css("--cv-side-bg", style.side)
        .css("--cv-text", style.text || "#111827");
      $scope.find("[data-bp-template-color-main]").val(style.main);
      $scope.find("[data-bp-template-color-side]").val(style.side);
      $scope.find("[data-bp-template-color-text]").val(style.text || "#111827");
    }

    $scope.on("click", "[data-bp-template]", function(){
      applyTemplate($(this).attr("data-bp-template"));
    });

    $scope.on("click", "[data-bp-template-add]", function(){
      var $input = $scope.find("[data-bp-template-name]").first();
      var name = ($input.val() || "").trim();
      if(!name) return;
      var key = "custom_" + Date.now();
      templateStyles[key] = {
        main: $scope.find("[data-bp-template-color-main]").first().val() || "#15776a",
        side: $scope.find("[data-bp-template-color-side]").first().val() || "#5caea3",
        text: $scope.find("[data-bp-template-color-text]").first().val() || "#111827"
      };
      var $grid = $scope.find("[data-bp-template-grid]").first();
      $grid.append('<button type="button" data-bp-template="' + esc(key) + '">' + esc(name) + "</button>");
      applyTemplate(key);
      $input.val("");
    });

    $scope.on("change input", "[data-bp-template-color-main], [data-bp-template-color-side], [data-bp-template-color-text]", function(){
      var active = $scope.find("[data-bp-template-grid] [data-bp-template].is-active").attr("data-bp-template") || "modern";
      if(!templateStyles[active]) templateStyles[active] = { main: "#15776a", side: "#5caea3", text: "#111827" };
      templateStyles[active].main = $scope.find("[data-bp-template-color-main]").first().val() || templateStyles[active].main;
      templateStyles[active].side = $scope.find("[data-bp-template-color-side]").first().val() || templateStyles[active].side;
      templateStyles[active].text = $scope.find("[data-bp-template-color-text]").first().val() || templateStyles[active].text;
      applyTemplate(active);
    });

    applyTemplate("modern");
  }

  function initTabs($scope){
    $scope.on("click", "[data-bp-cv-tab]", function(){
      var tab = $(this).attr("data-bp-cv-tab");
      $scope.find("[data-bp-cv-tab]").removeClass("is-active");
      $(this).addClass("is-active");
      $scope.find("[data-bp-cv-panel]").attr("hidden", true).removeClass("is-active");
      $scope.find('[data-bp-cv-panel="' + tab + '"]').attr("hidden", false).addClass("is-active");
    });
  }

  function initZoom($scope){
    var zoom = 0.65;
    var $paper = $scope.find(".bp-cv-paper").first();
    function applyZoom(){
      $paper.css("transform", "scale(" + zoom + ")");
      $scope.find(".bp-cv-bottom span").filter(function(){
        return $(this).text().indexOf("%") !== -1;
      }).first().text(Math.round(zoom * 100) + "%");
    }
    applyZoom();
    $scope.on("click", "[data-bp-cv-zoom]", function(){
      var mode = $(this).attr("data-bp-cv-zoom");
      if(mode === "in") zoom = Math.min(1.2, zoom + 0.05);
      if(mode === "out") zoom = Math.max(0.45, zoom - 0.05);
      applyZoom();
    });
  }

  function initPdf($scope){
    function printOnlyPreview(el){
      var popup = window.open("", "_blank");
      if(!popup) return false;

      var styles = "";
      $("link[rel='stylesheet']").each(function(){
        var href = $(this).attr("href");
        if(href) styles += '<link rel="stylesheet" href="' + esc(href) + '">';
      });

      var html = ""
        + "<!doctype html><html><head><meta charset='utf-8'><title>CV preview</title>"
        + styles
        + "<style>"
        + "@page{size:A4;margin:8mm;}body{margin:0;background:#fff;display:flex;justify-content:center;align-items:flex-start;}"
        + ".bp-cv-paper{transform:none !important;box-shadow:none !important;width:194mm !important;height:281mm !important;min-height:281mm !important;}"
        + ".bp-cv-wrap,.bp-cv-app,.bp-cv-main,.bp-cv-grid,.bp-cv-editor,.bp-cv-preview{all:unset;display:block;}"
        + "</style></head><body>"
        + el.outerHTML
        + "<script>window.onload=function(){setTimeout(function(){window.print();},150);};<\/script>"
        + "</body></html>";

      popup.document.open();
      popup.document.write(html);
      popup.document.close();
      return true;
    }

    $scope.on("click", "[data-bp-cv-download]", function(){
      var node = $scope.find(".bp-cv-panel.is-active .bp-cv-paper").first();
      if(!node.length) node = $scope.find(".bp-cv-paper").first();
      if(!node.length) return;
      var el = node.get(0);
      var previousTransform = el.style.transform;
      el.style.transform = "none";

      var filename = (($scope.find('[data-bp-cv-target="full_name"]').first().text() || "cv").trim().replace(/\s+/g, "_").toLowerCase()) + ".pdf";
      var PdfCtor = (window.jspdf && window.jspdf.jsPDF) || window.jsPDF;

      function restore(){ el.style.transform = previousTransform; }

      function fail(){
        restore();
        alert("PDF export niet beschikbaar in deze browserconfiguratie.");
      }

      if(typeof window.html2canvas === "function" && PdfCtor){
        window.html2canvas(el, {
          scale: 2,
          useCORS: true,
          backgroundColor: "#ffffff",
          scrollX: 0,
          scrollY: 0
        }).then(function(canvas){
          var pdf = new PdfCtor("p", "mm", "a4");
          var pageWidth = 210;
          var pageHeight = 297;
          var margin = 8;
          var usableWidth = pageWidth - (margin * 2);
          var usableHeight = pageHeight - (margin * 2);
          var imgData = canvas.toDataURL("image/png");
          var fitScale = Math.min(usableWidth / canvas.width, usableHeight / canvas.height);
          var drawWidth = canvas.width * fitScale;
          var drawHeight = canvas.height * fitScale;
          var drawX = margin + ((usableWidth - drawWidth) / 2);
          var drawY = margin + ((usableHeight - drawHeight) / 2);

          pdf.addImage(imgData, "PNG", drawX, drawY, drawWidth, drawHeight, undefined, "FAST");
          pdf.save(filename);
          restore();
        }).catch(fail);
        return;
      }

      var html2pdfFn = window.html2pdf;
      if(typeof html2pdfFn === "function"){
        try {
          var worker = html2pdfFn().from(el).set({
            margin: 6,
            filename: filename,
            html2canvas: { scale: 2, useCORS: true, backgroundColor: "#ffffff" },
            jsPDF: { unit: "mm", format: "a4", orientation: "portrait" }
          }).save();
          if(worker && typeof worker.then === "function"){
            worker.then(restore).catch(fail);
          } else {
            restore();
          }
          return;
        } catch(err){
          restore();
          if(!printOnlyPreview(el)) fail();
          return;
        }
      }

      restore();
      if(!printOnlyPreview(el)) fail();
    });
  }

  $(document).ready(function(){
    var $scope = $(".bp-cv-wrap");
    if(!$scope.length) return;
    bindSimpleFields($scope);
    photoPreview($scope);
    autoUpload($scope);
    initRepeaters($scope);
    initTemplates($scope);
    initTabs($scope);
    initZoom($scope);
    initPdf($scope);
  });
})(jQuery);
